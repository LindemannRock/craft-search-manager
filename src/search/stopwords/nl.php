<?php

/**
 * Dutch stop words for Search Manager search indexing
 *
 * Generic Dutch stop words that work across the Netherlands and Flanders.
 * Covers nl-NL (Netherlands) and nl-BE (Belgium/Flemish).
 *
 * For regional customization:
 * Copy to: config/search-manager/stopwords/nl-be.php
 *
 * Based on NLTK and Snowball Dutch stop word lists.
 *
 * @since 5.44.0
 */

return [
    // Articles
    'de', 'het', 'een', '\'t', '\'s',

    // Pronouns
    'ik', 'me', 'mij', 'mijn',
    'jij', 'je', 'jou', 'jouw',
    'hij', 'hem', 'zijn',
    'zij', 'haar',
    'we', 'wij', 'ons', 'onze',
    'jullie',
    'ze', 'hun', 'hen',
    'dit', 'dat', 'deze', 'die',
    'wat', 'wie', 'welke', 'welk', 'wiens', 'wier',
    'zich', 'zelf',

    // Prepositions
    'aan', 'bij', 'in', 'met', 'op', 'te', 'uit', 'van', 'voor',
    'naar', 'door', 'over', 'onder', 'tegen', 'tot', 'tussen',
    'zonder', 'na', 'om', 'binnen', 'buiten', 'achter', 'boven',

    // Conjunctions
    'en', 'of', 'maar', 'want', 'dus', 'omdat', 'als', 'dan',
    'toch', 'echter', 'hoewel', 'terwijl', 'daarna', 'daarom',
    'zodat', 'opdat', 'mits', 'tenzij',

    // Verbs (auxiliary and copular)
    'ben', 'bent', 'is', 'zijn', 'was', 'waren', 'geweest',
    'word', 'wordt', 'worden', 'werd', 'werden', 'geworden',
    'heb', 'hebt', 'heeft', 'hebben', 'had', 'hadden', 'gehad',
    'ga', 'gaat', 'gaan', 'ging', 'gingen', 'gegaan',
    'kan', 'kun', 'kunt', 'kunnen', 'kon', 'konden', 'gekund',
    'moet', 'moeten', 'moest', 'moesten', 'gemoeten',
    'wil', 'wilt', 'willen', 'wilde', 'wilden', 'gewild',
    'zal', 'zult', 'zullen', 'zou', 'zouden',
    'doe', 'doet', 'doen', 'deed', 'deden', 'gedaan',
    'mag', 'mogen', 'mocht', 'mochten',

    // Adverbs
    'al', 'ook', 'nog', 'wel', 'eens', 'zelfs', 'zeer', 'erg', 'heel',
    'even', 'goed', 'hier', 'daar', 'nu', 'toen', 'altijd', 'vaak',
    'soms', 'nooit', 'misschien', 'waarschijnlijk', 'inderdaad',
    'zo', 'heel', 'echt', 'natuurlijk',

    // Negations
    'niet', 'geen', 'nooit', 'nergens', 'niets',

    // Numbers
    'een', 'twee', 'drie', 'vier', 'vijf', 'zes', 'zeven', 'acht', 'negen', 'tien',
];
