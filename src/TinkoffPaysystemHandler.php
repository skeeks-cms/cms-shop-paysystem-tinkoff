<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\shop\tinkoff;

use skeeks\cms\helpers\StringHelper;
use skeeks\cms\shop\models\ShopBill;
use skeeks\cms\shop\models\ShopOrder;
use skeeks\cms\shop\models\ShopPayment;
use skeeks\cms\shop\paysystem\PaysystemHandler;
use skeeks\yii2\form\fields\BoolField;
use skeeks\yii2\form\fields\FieldSet;
use yii\base\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use yii\httpclient\Client;

/**
 * @author Semenov Alexander <semenov@skeeks.com>
 */
class TinkoffPaysystemHandler extends PaysystemHandler
{
    /**
     * @var
     */
    public $terminal_key;

    /**
     * @var
     */
    public $terminal_password;

    /**
     * @var bool Отправлять данные по чекам?
     */
    public $is_receipt = false;

    /**
     * @var string
     */
    public $tinkoff_url = "https://securepay.tinkoff.ru/v2/";

    /**
     * @return array
     */
    static public function descriptorConfig()
    {
        return array_merge(parent::descriptorConfig(), [
            'name' => "Tinkoff",
        ]);
    }


    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            [['terminal_key'], 'required'],
            [['terminal_key'], 'string'],
            [['terminal_password'], 'string'],
            [['is_receipt'], 'boolean'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'terminal_key'      => "ID терминала",
            'terminal_password' => "Пароль терминала",
            'is_receipt'        => "Отправлять данные для формирования чеков?",
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'terminal_key'      => "Указан в личном кабинете tinkiff",
            'terminal_password' => "Указан в личном кабинете tinkiff",
            'is_receipt'        => "Необходимо передавать, если вы отправляете данные для формирования чеков по одному из сценариев: Платеж и чек одновременно или Сначала чек, потом платеж.",
        ]);
    }


    /**
     * @return array
     */
    public function getConfigFormFields()
    {
        return [
            'main' => [
                'class'  => FieldSet::class,
                'name'   => 'Основные',
                'fields' => [
                    'terminal_key',

                    'terminal_password',

                    'is_receipt' => [
                        'class'     => BoolField::class,
                        'allowNull' => false,
                    ],
                ],
            ],

        ];
    }

    public function actionPayBill(ShopBill $shopBill)
    {
        $model = $shopBill;

        $yooKassa = $model->shopPaySystem->handler;
        $money = $model->money->convertToCurrency("RUB");
        $returnUrl = $shopBill->getUrl([], true);
        $successUrl = $shopBill->getUrl(['success_paied' => true], true);
        $failUrl = $shopBill->getUrl(['fail_paied' => true], true);

        /**
         * Для чеков нужно указывать информацию о товарах
         * https://yookassa.ru/developers/api?lang=php#create_payment
         */

        $data = [
            'TerminalKey'     => $this->terminal_key,
            'Amount'          => $money->amount * 100,
            'OrderId'         => $model->id,
            'Description'     => $model->description,
            'NotificationURL' => Url::to(['/tinkoff/tinkoff/notify'], true),
            'SuccessURL'      => $successUrl,
            'FailURL'         => $failUrl,
        ];


        $receipt = [];


        $email = null;
        $phone = null;
        if ($model->shopOrder && $model->shopOrder->contact_email) {
            $data["DATA"]["Email"] = $model->shopOrder->contact_email;
        }


        /**
         * @see https://www.tbank.ru/kassa/dev/payments/#section/Podpis-zaprosa
         * 1) Собрать массив передаваемых данных в виде пар Ключ-Значения. В массив нужно добавить только параметры корневого объекта. Вложенные объекты и массивы не участвуют в расчете токена. В примере ниже в массив включены параметры TerminalKey, Amount, OrderId, Description и исключен объект Receipt и DATA.
         */
        $copyData = $data;
        ArrayHelper::remove($copyData, 'DATA');
        ArrayHelper::remove($copyData, 'Receipt');
        /*ArrayHelper::remove($copyData, 'NotificationURL');
        ArrayHelper::remove($copyData, 'SuccessURL');
        ArrayHelper::remove($copyData, 'FailURL');*/

        /**
         * 2 Добавить в массив пару {Password, Значение пароля}. Пароль можно найти в личном кабинете Мерчанта
         */
        $copyData['Password'] = $this->terminal_password;

        /**
         * 3 Отсортировать массив по алфавиту по ключу.
         */
        ksort($copyData);
        /**
         * 4 Конкатенировать только значения пар в одну строку.
         */
        $tokenString = implode($copyData);
        /**
         * 5 Применить к строке хеш-функцию SHA-256 (с поддержкой UTF-8).
         */
        $token = hash('sha256', $tokenString);

        /**
         * 6 Добавить получившийся результат в значение параметра Token в тело запроса и отправить запрос.
         */
        $data['Token'] = $token;


        $client = new Client();
        $request = $client
            ->post($this->tinkoff_url."Init")
            ->setFormat(Client::FORMAT_JSON)
            ->setData($data);;

        \Yii::info(print_r($data, true), self::class);

        $response = $request->send();
        if (!$response->isOk) {
            \Yii::error($response->content, self::class);
            throw new Exception('Tinkoff api not found');
        }

        if (!ArrayHelper::getValue($response->data, "PaymentId")) {
            \Yii::error(print_r($response->data, true), self::class);
            throw new Exception('Tinkoff kassa payment id not found: '.print_r($response->data, true));
        }

        $model->external_id = ArrayHelper::getValue($response->data, "PaymentId");
        $model->external_data = $response->data;

        if (!$model->save()) {
            throw new Exception("Не удалось сохранить платеж: ".print_r($model->errors, true));
        }

        return \Yii::$app->response->redirect(ArrayHelper::getValue($response->data, "PaymentURL"));
    }

    /**
     * @param ShopPayment $shopPayment
     * @return \yii\console\Response|\yii\web\Response
     */
    public function actionPayOrder(ShopOrder $shopOrder)
    {
        $model = $this->getShopBill($shopOrder);

        $yooKassa = $model->shopPaySystem->handler;
        $money = $model->money->convertToCurrency("RUB");
        $returnUrl = $shopOrder->getUrl([], true);
        $successUrl = $shopOrder->getUrl(['success_paied' => true], true);
        $failUrl = $shopOrder->getUrl(['fail_paied' => true], true);

        /**
         * Для чеков нужно указывать информацию о товарах
         * https://yookassa.ru/developers/api?lang=php#create_payment
         */

        $data = [
            'TerminalKey'     => $this->terminal_key,
            'Amount'          => $money->amount * 100,
            'OrderId'         => $model->id,
            'Description'     => $model->description,
            'NotificationURL' => Url::to(['/tinkoff/tinkoff/notify'], true),
            'SuccessURL'      => $successUrl,
            'FailURL'         => $failUrl,
        ];


        $receipt = [];
        if ($yooKassa->is_receipt) {

            $receipt['Email'] = \Yii::$app->cms->adminEmail;
            if (trim($shopOrder->contact_email)) {
                $receipt['Email'] = trim($shopOrder->contact_email);
            }
            $receipt['Taxation'] = "usn_income"; //todo: вынести в настройки

            foreach ($shopOrder->shopOrderItems as $shopOrderItem) {
                $itemData = [];


                /**
                 * @see https://www.tinkoff.ru/kassa/develop/api/payments/init-request/#Items
                 */
                $itemData['Name'] = StringHelper::substr($shopOrderItem->name, 0, 128);
                $itemData['Quantity'] = (float)$shopOrderItem->quantity;
                $itemData['Tax'] = "none"; //todo: доработать этот момент
                $itemData['Price'] = $shopOrderItem->money->amount * 100;
                $itemData['Amount'] = $shopOrderItem->money->amount * $shopOrderItem->quantity * 100;

                $receipt['Items'][] = $itemData;
            }

            /**
             * Стоимость доставки так же нужно добавить
             */
            if ((float)$shopOrder->moneyDelivery->amount > 0) {
                $itemData = [];
                $itemData['Name'] = StringHelper::substr($shopOrder->shopDelivery->name, 0, 128);
                $itemData['Quantity'] = 1;
                $itemData['Tax'] = "none";
                $itemData['Amount'] = $shopOrder->moneyDelivery->amount * 100;
                $itemData['Price'] = $shopOrder->moneyDelivery->amount * 100;

                $receipt['Items'][] = $itemData;
            }

            $totalCalcAmount = 0;
            foreach ($receipt['Items'] as $itemData) {
                $totalCalcAmount = $totalCalcAmount + ($itemData['Amount']);
            }

            $discount = 0;
            if ($totalCalcAmount > (float)$money->amount) {
                $discount = abs((float)$money->amount * 100 - $totalCalcAmount);
            }

            /**
             * Стоимость скидки
             */
            //todo: тут можно еще подумать, это временное решение
            if ($discount > 0) {
                $discountValue = $discount;
                foreach ($receipt['Items'] as $key => $item) {
                    if ($discountValue == 0) {
                        break;
                    }
                    if ($item['Amount']) {
                        if ($item['Amount'] >= $discountValue) {
                            $item['Amount'] = $item['Amount'] - $discountValue;
                            $discountValue = 0;
                        } else {
                            $item['Amount'] = 1;
                            $discountValue = $discountValue - $item['Amount'] - 1;
                        }
                    }

                    $item['Price'] = $item['Amount'];

                    $receipt['Items'][$key] = $item;
                }
                //$receipt['items'][] = $itemData;


            }


            $data["Receipt"] = $receipt;
        }


        $email = null;
        $phone = null;
        if ($model->shopOrder && $model->shopOrder->contact_email) {
            $data["DATA"]["Email"] = $model->shopOrder->contact_email;
        }


        /**
         * @see https://www.tbank.ru/kassa/dev/payments/#section/Podpis-zaprosa
         * 1) Собрать массив передаваемых данных в виде пар Ключ-Значения. В массив нужно добавить только параметры корневого объекта. Вложенные объекты и массивы не участвуют в расчете токена. В примере ниже в массив включены параметры TerminalKey, Amount, OrderId, Description и исключен объект Receipt и DATA.
         */
        $copyData = $data;
        ArrayHelper::remove($copyData, 'DATA');
        ArrayHelper::remove($copyData, 'Receipt');
        /*ArrayHelper::remove($copyData, 'NotificationURL');
        ArrayHelper::remove($copyData, 'SuccessURL');
        ArrayHelper::remove($copyData, 'FailURL');*/

        /**
         * 2 Добавить в массив пару {Password, Значение пароля}. Пароль можно найти в личном кабинете Мерчанта
         */
        $copyData['Password'] = $this->terminal_password;

        /**
         * 3 Отсортировать массив по алфавиту по ключу.
         */
        ksort($copyData);
        /**
         * 4 Конкатенировать только значения пар в одну строку.
         */
        $tokenString = implode($copyData);
        /**
         * 5 Применить к строке хеш-функцию SHA-256 (с поддержкой UTF-8).
         */
        $token = hash('sha256', $tokenString);

        /**
         * 6 Добавить получившийся результат в значение параметра Token в тело запроса и отправить запрос.
         */
        $data['Token'] = $token;


        $client = new Client();
        $request = $client
            ->post($this->tinkoff_url."Init")
            ->setFormat(Client::FORMAT_JSON)
            ->setData($data);;

        \Yii::info(print_r($data, true), self::class);

        $response = $request->send();
        if (!$response->isOk) {
            \Yii::error($response->content, self::class);
            throw new Exception('Tinkoff api not found');
        }

        if (!ArrayHelper::getValue($response->data, "PaymentId")) {
            \Yii::error(print_r($response->data, true), self::class);
            throw new Exception('Tinkoff kassa payment id not found: '.print_r($response->data, true));
        }

        $model->external_id = ArrayHelper::getValue($response->data, "PaymentId");
        $model->external_data = $response->data;

        if (!$model->save()) {
            throw new Exception("Не удалось сохранить платеж: ".print_r($model->errors, true));
        }

        return \Yii::$app->response->redirect(ArrayHelper::getValue($response->data, "PaymentURL"));
    }
}