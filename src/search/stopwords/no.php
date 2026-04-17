<?php

/**
 * Norwegian stop words for Search Manager search indexing
 *
 * Covers both Bokmål (nb) and Nynorsk (nn) — the two official written
 * standards of Norwegian. Both variants share most stop words; Nynorsk-only
 * forms (ikkje, kva, kven, kor) are included so either standard tokenises
 * correctly.
 *
 * For single-variant tuning:
 * Copy to: config/search-manager/stopwords/nb.php or nn.php
 *
 * Based on NLTK and Snowball Norwegian stop word lists.
 *
 * @since 5.44.0
 */

return [
    // Articles
    'en', 'et', 'ei',

    // Pronouns
    'jeg', 'eg', 'du', 'han', 'hun', 'ho', 'det', 'den',
    'vi', 'me', 'dere', 'de', 'dei',
    'meg', 'deg', 'ham', 'henne', 'oss', 'dem',
    // Possessives
    'min', 'mitt', 'mi', 'mine',
    'din', 'ditt', 'di', 'dine',
    'sin', 'sitt', 'si', 'sine',
    'vår', 'vårt', 'våre',
    'deres', 'dykk', 'dykkar',
    'hans', 'hennes', 'hennar', 'dens', 'dets',
    // Demonstratives
    'denne', 'dette', 'disse', 'desse',
    'her', 'der',
    // Interrogatives/relatives (Bokmål + Nynorsk)
    'som', 'hva', 'kva', 'hvem', 'kven', 'hvilken', 'hvilket', 'hvilke',
    'hvor', 'kor', 'hvordan', 'korleis', 'hvorfor', 'kvifor', 'når',

    // Prepositions
    'i', 'på', 'av', 'til', 'fra', 'frå', 'for', 'med', 'om', 'ved',
    'over', 'under', 'mellom', 'etter', 'gjennom', 'hos', 'mot',
    'uten', 'utan', 'innen', 'trass', 'ad',

    // Conjunctions
    'og', 'eller', 'men', 'at',
    'så', 'for', 'fordi', 'selv', 'sjølv', 'hvis', 'viss',
    'mens', 'da', 'når', 'om',

    // Verbs (auxiliary and copular)
    'er', 'var', 'vært', 'vore', 'være', 'vera',
    'har', 'hadde', 'hatt', 'ha',
    'blir', 'ble', 'vart', 'blitt', 'vorte', 'bli',
    'gjør', 'gjorde', 'gjort', 'gjøre', 'gjera',
    'kan', 'kunne', 'kunnet',
    'skal', 'skulle',
    'vil', 'ville',
    'må', 'måtte',
    'får', 'fikk', 'fekk', 'fått', 'få',
    'går', 'gikk', 'gått', 'gå',

    // Adverbs
    'mye', 'mykje', 'lite', 'mer', 'meir', 'mindre', 'mest', 'minst',
    'godt', 'vel', 'dårlig', 'ille',
    'alltid', 'aldri', 'ofte', 'iblant', 'sjelden',
    'nå', 'no', 'da', 'sidan', 'snart',
    'her', 'der', 'dit',
    'så', 'jo', 'bare', 'berre', 'også', 'òg', 'selv', 'enda', 'ennå',

    // Negations (Bokmål ikke + Nynorsk ikkje)
    'ikke', 'ikkje', 'ei', 'ingen', 'inget', 'ingenting', 'aldri',

    // Numbers
    'en', 'to', 'tre', 'fire', 'fem',
    'seks', 'sju', 'syv', 'åtte', 'ni', 'ti',
];
