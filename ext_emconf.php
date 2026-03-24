<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI Chat',
    'description' => 'AI chat assistant for the TYPO3 backend',
    'category' => 'module',
    'version' => '0.1.1',
    'state' => 'alpha',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => 'typo3@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.99.99',
            'typo3' => '13.4.0-14.99.99',
            'nr_llm' => '0.6.0-0.99.99',
        ],
    ],
];
