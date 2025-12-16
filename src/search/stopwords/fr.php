<?php

/**
 * French stop words for Search Manager search indexing
 *
 * Generic French stop words that work across French-speaking regions
 * Covers fr-FR (France), fr-CA (Canada), fr-BE (Belgium), fr-CH (Switzerland)
 *
 * For regional customization:
 * Copy to: config/search-manager/stopwords/fr-ca.php for Quebec French
 *
 * @since 5.0.0
 */

return [
    // Articles
    'le', 'la', 'les', 'un', 'une', 'des',
    'du', 'de', 'd', 'au', 'aux',

    // Pronouns
    'je', 'tu', 'il', 'elle', 'nous', 'vous', 'ils', 'elles',
    'me', 'te', 'se', 'moi', 'toi', 'lui', 'leur', 'eux',
    'mon', 'ma', 'mes', 'ton', 'ta', 'tes', 'son', 'sa', 'ses',
    'notre', 'nos', 'votre', 'vos', 'leur', 'leurs',
    'ce', 'cet', 'cette', 'ces',
    'quel', 'quelle', 'quels', 'quelles',
    'qui', 'que', 'quoi', 'dont', 'où',
    'on', 'y', 'en',

    // Prepositions
    'à', 'dans', 'par', 'pour', 'en', 'vers', 'avec',
    'sans', 'sous', 'sur', 'chez', 'entre', 'parmi',
    'contre', 'depuis', 'pendant', 'avant', 'après',
    'devant', 'derrière', 'autour', 'près', 'loin',

    // Conjunctions
    'et', 'ou', 'mais', 'donc', 'or', 'ni', 'car',
    'comme', 'si', 'quand', 'lorsque', 'puisque',
    'parce', 'que', 'quoique', 'bien',

    // Verbs (common auxiliary and modal)
    'être', 'avoir', 'faire', 'aller', 'pouvoir', 'vouloir',
    'devoir', 'savoir', 'falloir',
    'est', 'sont', 'était', 'étaient', 'été',
    'a', 'ont', 'avait', 'avaient', 'eu',
    'fait', 'font', 'faisait', 'faisaient',
    'va', 'vont', 'allait', 'allaient', 'allé',
    'peut', 'peuvent', 'pouvait', 'pouvaient', 'pu',
    'veut', 'veulent', 'voulait', 'voulaient', 'voulu',
    'doit', 'doivent', 'devait', 'devaient', 'dû',

    // Adverbs
    'ne', 'pas', 'plus', 'jamais', 'rien', 'personne',
    'aucun', 'aucune', 'ni', 'non',
    'oui', 'si',
    'très', 'trop', 'assez', 'peu', 'beaucoup', 'bien',
    'mal', 'mieux', 'pire',
    'ici', 'là', 'ailleurs',
    'maintenant', 'alors', 'toujours', 'souvent', 'parfois',
    'jamais', 'déjà', 'encore', 'bientôt',
    'aujourd', 'hui', 'demain', 'hier',
    'aussi', 'ainsi', 'même', 'seulement', 'plutôt',

    // Others
    'tout', 'toute', 'tous', 'toutes',
    'autre', 'autres', 'même', 'mêmes',
    'tel', 'telle', 'tels', 'telles',
    'chaque', 'quelque', 'quelques', 'certain', 'certains',
    'plusieurs', 'aucun', 'nul', 'nulle',
    'ceci', 'cela', 'ça',
];
