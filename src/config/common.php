<?php
return [
    'components' => [
        'shop' => [
            'paysystemHandlers' => [
                \skeeks\cms\shop\tinkoff\TinkoffPaysystemHandler::class
            ],
        ],

        'log' => [
            'targets' => [
                [
                    'class'      => 'yii\log\FileTarget',
                    'levels'     => ['info'],
                    'logVars'    => [],
                    'categories' => [\skeeks\cms\shop\tinkoff\controllers\TinkoffController::class, \skeeks\cms\shop\tinkoff\TinkoffPaysystemHandler::class],
                    'logFile'    => '@runtime/logs/tinkoff-info.log',
                ],

                [
                    'class'      => 'yii\log\FileTarget',
                    'levels'     => ['error'],
                    'logVars'    => [],
                    'categories' => [\skeeks\cms\shop\tinkoff\controllers\TinkoffController::class, \skeeks\cms\shop\tinkoff\TinkoffPaysystemHandler::class],
                    'logFile'    => '@runtime/logs/tinkoff-errors.log',
                ],
            ],
        ],
    ],
];