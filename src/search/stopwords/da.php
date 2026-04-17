<?php

/**
 * Danish stop words for Search Manager search indexing
 *
 * Generic Danish stop words for da-DK.
 *
 * Based on NLTK and Snowball Danish stop word lists.
 *
 * @since 5.44.0
 */

return [
    // Articles
    'en', 'et',

    // Pronouns
    'jeg', 'du', 'han', 'hun', 'den', 'det', 'vi', 'i', 'de',
    'mig', 'dig', 'ham', 'hende', 'os', 'jer', 'dem',
    // Possessives
    'min', 'mit', 'mine',
    'din', 'dit', 'dine',
    'sin', 'sit', 'sine',
    'vores', 'jeres', 'deres',
    'hans', 'hendes', 'dens', 'dets',
    // Demonstratives
    'denne', 'dette', 'disse',
    'her', 'der',
    // Interrogatives/relatives
    'som', 'hvad', 'hvem', 'hvilken', 'hvilket', 'hvilke',
    'hvornår', 'hvor', 'hvorfor', 'hvordan',

    // Prepositions
    'i', 'på', 'af', 'til', 'fra', 'for', 'med', 'om', 'ved',
    'over', 'under', 'mellem', 'efter', 'gennem', 'hos', 'mod',
    'uden', 'inden', 'trods', 'ad', 'ifølge',

    // Conjunctions
    'og', 'eller', 'men', 'at',
    'så', 'for', 'fordi', 'selvom', 'hvis',
    'mens', 'da', 'når', 'om',

    // Verbs (auxiliary and copular)
    'er', 'var', 'været', 'være', 'værende',
    'har', 'havde', 'haft', 'have',
    'bliver', 'blev', 'blevet', 'blive',
    'gør', 'gjorde', 'gjort', 'gøre',
    'kan', 'kunne', 'kunnet',
    'skal', 'skulle',
    'vil', 'ville', 'villet',
    'må', 'måtte',
    'får', 'fik', 'fået', 'få',
    'går', 'gik', 'gået', 'gå',

    // Adverbs
    'meget', 'lidt', 'mere', 'mindre', 'mest', 'mindst',
    'godt', 'vel', 'dårligt',
    'altid', 'aldrig', 'ofte', 'sommetider', 'sjældent',
    'nu', 'da', 'siden', 'snart',
    'her', 'der', 'dertil', 'herhen',
    'så', 'jo', 'bare', 'også', 'selv', 'endnu', 'allerede',

    // Negations
    'ikke', 'ej', 'ingen', 'intet', 'aldrig',

    // Numbers
    'en', 'to', 'tre', 'fire', 'fem',
    'seks', 'syv', 'otte', 'ni', 'ti',
];
