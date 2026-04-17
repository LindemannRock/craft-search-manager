<?php

/**
 * Swedish stop words for Search Manager search indexing
 *
 * Generic Swedish stop words for sv-SE (Sweden) and sv-FI (Finland Swedish).
 *
 * Based on NLTK and Snowball Swedish stop word lists.
 *
 * @since 5.44.0
 */

return [
    // Articles
    'en', 'ett',

    // Pronouns
    'jag', 'du', 'han', 'hon', 'den', 'det', 'vi', 'ni', 'de',
    'mig', 'dig', 'honom', 'henne', 'oss', 'er', 'dem',
    // Possessives
    'min', 'mitt', 'mina',
    'din', 'ditt', 'dina',
    'sin', 'sitt', 'sina',
    'vår', 'vårt', 'våra',
    'er', 'ert', 'era',
    'deras', 'hans', 'hennes', 'dess',
    // Demonstratives
    'denna', 'detta', 'dessa',
    'här', 'där',
    // Interrogatives/relatives
    'som', 'vad', 'vem', 'vilken', 'vilket', 'vilka',
    'när', 'var', 'vart', 'varför', 'hur',

    // Prepositions
    'i', 'på', 'av', 'till', 'från', 'för', 'med', 'om', 'vid',
    'över', 'under', 'mellan', 'efter', 'genom', 'hos', 'mot',
    'utan', 'innan', 'trots', 'åt', 'enligt', 'utom',

    // Conjunctions
    'och', 'eller', 'men', 'att', 'att',
    'så', 'för', 'fast', 'även', 'ändå',
    'medan', 'då', 'när', 'om',

    // Verbs (auxiliary and copular)
    'är', 'var', 'varit', 'vara', 'varandes',
    'har', 'hade', 'haft', 'ha', 'havandes',
    'blir', 'blev', 'blivit', 'bli',
    'gör', 'gjorde', 'gjort', 'göra',
    'kan', 'kunde', 'kunnat', 'kunna',
    'ska', 'skall', 'skulle',
    'vill', 'ville', 'velat', 'vilja',
    'måste',
    'får', 'fick', 'fått', 'få',
    'går', 'gick', 'gått', 'gå',

    // Adverbs
    'mycket', 'lite', 'mer', 'mindre', 'mest', 'minst',
    'bra', 'väl', 'illa', 'dåligt',
    'alltid', 'aldrig', 'ofta', 'ibland', 'sällan',
    'nu', 'då', 'sedan', 'nyss', 'snart',
    'här', 'där', 'dit', 'hit',
    'så', 'ju', 'just', 'bara', 'också', 'även', 'ännu', 'redan',

    // Negations
    'inte', 'ej', 'icke', 'ingen', 'inget', 'inga', 'aldrig',

    // Numbers
    'en', 'två', 'tre', 'fyra', 'fem',
    'sex', 'sju', 'åtta', 'nio', 'tio',
];
