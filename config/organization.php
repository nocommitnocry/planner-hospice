<?php
declare(strict_types=1);

/**
 * Configurazione di personalizzazione per la singola organizzazione.
 *
 * Modificare questo file per adattare l'applicazione a un'organizzazione
 * diversa (es. laboratorio universitario invece di hospice).
 *
 * I tipi turno e le categorie operatori sono in DB e modificabili da UI;
 * qui stanno solo i valori "di sistema" che condizionano la generazione
 * automatica e la verifica di copertura.
 */
return [
    // Nome esposto in interfaccia
    'name' => 'Hospice di Abbiategrasso',

    // Ore lavorate standard
    'ore_contrattuali_default' => 165.00,
    'ore_giornaliere_standard' => 7.50,

    // Riposo minimo legale tra due turni consecutivi
    'soglia_riposo_minimo_ore' => 11,

    // Soglia oltre cui un saldo positivo è considerato "straordinario significativo"
    'soglia_straordinari_ore' => 20,

    // Pattern di turnazione lineari (vedi PatternManager nella sessione 4)
    'pattern_turnazione' => [
        'standard'    => ['M', 'M', 'P', 'N', 'S', 'R'],
        'no_notti'    => ['M', 'M', 'P', 'P', 'R', 'R'],
        'coordinator' => ['G', 'G', 'G', 'G', 'G', 'R', 'R'],
    ],

    // Requisiti minimo/massimo di personale per turno e categoria.
    // Le chiavi di primo livello sono i codici tipo_turno; quelle di secondo
    // livello i nomi categoria (case-insensitive matching).
    'requisiti_copertura' => [
        'M' => [
            'INFERMIERE' => ['min' => 2, 'max' => 2],
            'OSS'        => ['min' => 2, 'max' => 3],
        ],
        'P' => [
            'INFERMIERE' => ['min' => 1, 'max' => 1],
            'OSS'        => ['min' => 1, 'max' => 1],
        ],
        'N' => [
            'INFERMIERE' => ['min' => 1, 'max' => 1],
            'OSS'        => ['min' => 1, 'max' => 1],
        ],
    ],
];
