<?php

/**
 * Italian stop words for Search Manager search indexing
 *
 * Generic Italian stop words that work across all Italian-speaking regions.
 * Covers it-IT (Italy) and it-CH (Switzerland).
 *
 * For regional customization:
 * Copy to: config/search-manager/stopwords/it-ch.php
 *
 * Based on NLTK and Snowball Italian stop word lists.
 *
 * @since 5.44.0
 */

return [
    // Articles
    'il', 'lo', 'la', 'i', 'gli', 'le',
    'un', 'uno', 'una', 'un\'',

    // Pronouns
    'io', 'tu', 'lui', 'lei', 'noi', 'voi', 'loro',
    'mi', 'ti', 'ci', 'vi', 'si', 'me', 'te', 'se', 'ne',
    // Possessives
    'mio', 'mia', 'miei', 'mie',
    'tuo', 'tua', 'tuoi', 'tue',
    'suo', 'sua', 'suoi', 'sue',
    'nostro', 'nostra', 'nostri', 'nostre',
    'vostro', 'vostra', 'vostri', 'vostre',
    // Demonstratives
    'questo', 'questa', 'questi', 'queste', 'quest\'',
    'quello', 'quella', 'quelli', 'quelle', 'quell\'',
    'codesto', 'codesta', 'codesti', 'codeste',

    // Prepositions
    'a', 'ad', 'di', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra',
    // Articulated prepositions
    'al', 'allo', 'alla', 'ai', 'agli', 'alle',
    'del', 'dello', 'della', 'dei', 'degli', 'delle',
    'dal', 'dallo', 'dalla', 'dai', 'dagli', 'dalle',
    'nel', 'nello', 'nella', 'nei', 'negli', 'nelle',
    'sul', 'sullo', 'sulla', 'sui', 'sugli', 'sulle',
    'col', 'coi',

    // Conjunctions
    'e', 'ed', 'o', 'od', 'ma', 'però', 'anche', 'se', 'perché',
    'come', 'quando', 'mentre', 'poiché', 'affinché', 'nonostante',
    'benché', 'sebbene', 'finché', 'quindi', 'dunque', 'allora',

    // Verbs (auxiliary and copular)
    'è', 'sono', 'sei', 'siamo', 'siete',
    'era', 'eri', 'eravamo', 'eravate', 'erano',
    'fui', 'fosti', 'fu', 'fummo', 'foste', 'furono',
    'sarò', 'sarai', 'sarà', 'saremo', 'sarete', 'saranno',
    'essere', 'essendo', 'stato', 'stata', 'stati', 'state',
    'ho', 'hai', 'ha', 'abbiamo', 'avete', 'hanno',
    'avevo', 'avevi', 'aveva', 'avevamo', 'avevate', 'avevano',
    'ebbi', 'avesti', 'ebbe', 'avemmo', 'aveste', 'ebbero',
    'avere', 'avendo', 'avuto', 'avuta', 'avuti', 'avute',
    'faccio', 'fai', 'fa', 'facciamo', 'fate', 'fanno',
    'fare', 'fatto', 'fatta', 'fatti', 'fatte',
    'vado', 'vai', 'va', 'andiamo', 'andate', 'vanno',
    'andare', 'andato', 'andata', 'andati', 'andate',
    'posso', 'puoi', 'può', 'possiamo', 'potete', 'possono',
    'potere', 'potuto',
    'devo', 'devi', 'deve', 'dobbiamo', 'dovete', 'devono',
    'dovere', 'dovuto',
    'voglio', 'vuoi', 'vuole', 'vogliamo', 'volete', 'vogliono',
    'volere', 'voluto',

    // Adverbs
    'molto', 'poco', 'più', 'meno', 'bene', 'male',
    'sempre', 'mai', 'spesso', 'talvolta', 'raramente',
    'ora', 'adesso', 'oggi', 'ieri', 'domani',
    'qui', 'qua', 'là', 'lì', 'là',
    'così', 'tanto', 'troppo', 'abbastanza',

    // Negations
    'non', 'né', 'neanche', 'nemmeno', 'neppure', 'niente', 'nulla', 'nessuno',

    // Numbers
    'uno', 'due', 'tre', 'quattro', 'cinque',
    'sei', 'sette', 'otto', 'nove', 'dieci',
];
