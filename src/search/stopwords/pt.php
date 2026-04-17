<?php

/**
 * Portuguese stop words for Search Manager search indexing
 *
 * Generic Portuguese stop words that work across all Portuguese-speaking
 * regions. Covers pt-PT (Portugal), pt-BR (Brazil), pt-AO (Angola), etc.
 *
 * For regional customization:
 * Copy to: config/search-manager/stopwords/pt-br.php or pt-pt.php
 *
 * Based on NLTK and Snowball Portuguese stop word lists.
 *
 * @since 5.44.0
 */

return [
    // Articles
    'o', 'a', 'os', 'as',
    'um', 'uma', 'uns', 'umas',

    // Pronouns
    'eu', 'tu', 'ele', 'ela', 'nós', 'vós', 'eles', 'elas',
    'me', 'te', 'se', 'lhe', 'lhes', 'nos', 'vos',
    'mim', 'ti', 'si',
    // Possessives
    'meu', 'minha', 'meus', 'minhas',
    'teu', 'tua', 'teus', 'tuas',
    'seu', 'sua', 'seus', 'suas',
    'nosso', 'nossa', 'nossos', 'nossas',
    'vosso', 'vossa', 'vossos', 'vossas',
    // Demonstratives
    'este', 'esta', 'estes', 'estas', 'isto',
    'esse', 'essa', 'esses', 'essas', 'isso',
    'aquele', 'aquela', 'aqueles', 'aquelas', 'aquilo',
    // Interrogatives/relatives
    'que', 'quem', 'qual', 'quais', 'quanto', 'quanta', 'quantos', 'quantas',
    'onde', 'quando', 'como',

    // Prepositions
    'a', 'ante', 'após', 'até', 'com', 'contra', 'de', 'desde',
    'em', 'entre', 'para', 'por', 'perante', 'sem', 'sob', 'sobre', 'trás',
    // Contractions
    'ao', 'aos', 'à', 'às',
    'do', 'dos', 'da', 'das',
    'no', 'nos', 'na', 'nas',
    'pelo', 'pela', 'pelos', 'pelas',
    'num', 'numa', 'nuns', 'numas',
    'dum', 'duma', 'duns', 'dumas',

    // Conjunctions
    'e', 'ou', 'mas', 'porém', 'todavia', 'contudo', 'entretanto',
    'porque', 'pois', 'portanto', 'logo', 'assim',
    'se', 'quando', 'enquanto', 'embora', 'ainda',

    // Verbs (auxiliary and copular)
    'é', 'são', 'sou', 'és', 'somos', 'sois',
    'era', 'eras', 'éramos', 'éreis', 'eram',
    'fui', 'foste', 'foi', 'fomos', 'fostes', 'foram',
    'serei', 'serás', 'será', 'seremos', 'sereis', 'serão',
    'seja', 'sejas', 'sejamos', 'sejais', 'sejam',
    'ser', 'sendo', 'sido',
    'está', 'estão', 'estou', 'estás', 'estamos', 'estais',
    'estava', 'estavas', 'estávamos', 'estáveis', 'estavam',
    'estive', 'estiveste', 'esteve', 'estivemos', 'estivestes', 'estiveram',
    'estar', 'estando', 'estado',
    'tem', 'têm', 'tenho', 'tens', 'temos', 'tendes',
    'tinha', 'tinhas', 'tínhamos', 'tínheis', 'tinham',
    'tive', 'tiveste', 'teve', 'tivemos', 'tivestes', 'tiveram',
    'ter', 'tendo', 'tido',
    'há', 'havia', 'houve', 'haver',
    'faz', 'fazem', 'faço', 'fazes', 'fazemos', 'fazeis',
    'fazer', 'fazendo', 'feito',
    'vai', 'vão', 'vou', 'vais', 'vamos', 'ides',
    'ir', 'indo', 'ido',

    // Adverbs
    'muito', 'pouco', 'mais', 'menos', 'bem', 'mal',
    'sempre', 'nunca', 'jamais', 'ainda', 'já', 'ora', 'agora',
    'aqui', 'ali', 'lá', 'aí', 'cá',
    'hoje', 'ontem', 'amanhã',
    'assim', 'também', 'tão', 'tanto', 'bastante',

    // Negations
    'não', 'nao', 'nem', 'nenhum', 'nenhuma', 'nada', 'ninguém',

    // Numbers
    'um', 'dois', 'três', 'quatro', 'cinco',
    'seis', 'sete', 'oito', 'nove', 'dez',
];
