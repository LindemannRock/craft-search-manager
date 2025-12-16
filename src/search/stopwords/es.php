<?php

/**
 * Spanish stop words for Search Manager search indexing
 *
 * Generic Spanish stop words that work across Spanish-speaking regions
 * Covers es-ES (Spain), es-MX (Mexico), es-AR (Argentina), es-CO (Colombia), etc.
 *
 * For regional customization:
 * Copy to: config/search-manager/stopwords/es-mx.php for Mexican Spanish
 *
 * @since 5.0.0
 */

return [
    // Articles
    'el', 'la', 'los', 'las',
    'un', 'una', 'unos', 'unas',
    'al', 'del',

    // Pronouns
    'yo', 'tú', 'él', 'ella', 'nosotros', 'nosotras', 'vosotros', 'vosotras',
    'ellos', 'ellas', 'usted', 'ustedes',
    'me', 'te', 'se', 'le', 'les', 'lo', 'la', 'nos', 'os',
    'mi', 'mis', 'tu', 'tus', 'su', 'sus',
    'nuestro', 'nuestra', 'nuestros', 'nuestras',
    'vuestro', 'vuestra', 'vuestros', 'vuestras',
    'este', 'esta', 'estos', 'estas',
    'ese', 'esa', 'esos', 'esas',
    'aquel', 'aquella', 'aquellos', 'aquellas',
    'esto', 'eso', 'aquello',

    // Prepositions
    'a', 'ante', 'bajo', 'con', 'contra', 'de', 'desde', 'durante',
    'en', 'entre', 'hacia', 'hasta', 'mediante', 'para', 'por',
    'según', 'sin', 'sobre', 'tras',

    // Conjunctions
    'y', 'e', 'o', 'u', 'pero', 'sino', 'mas',
    'aunque', 'porque', 'pues', 'si', 'como', 'cuando',

    // Verbs (common auxiliary and modal)
    'ser', 'estar', 'haber', 'tener', 'hacer', 'poder', 'deber',
    'es', 'son', 'era', 'eran', 'fue', 'fueron', 'sido',
    'está', 'están', 'estaba', 'estaban', 'estuvo', 'estado',
    'ha', 'han', 'había', 'habían', 'hubo', 'habido',
    'tiene', 'tienen', 'tenía', 'tenían', 'tuvo', 'tenido',
    'hace', 'hacen', 'hacía', 'hacían', 'hizo', 'hecho',
    'puede', 'pueden', 'podía', 'podían', 'pudo', 'podido',
    'debe', 'deben', 'debía', 'debían', 'debido',
    'va', 'van', 'iba', 'iban', 'ido',

    // Adverbs
    'no', 'ni', 'nunca', 'tampoco', 'nada', 'nadie',
    'sí', 'también', 'siempre', 'algo', 'alguien',
    'muy', 'mucho', 'poco', 'bastante', 'demasiado',
    'más', 'menos', 'tan', 'tanto', 'cuanto',
    'aquí', 'ahí', 'allí', 'donde', 'cuando',
    'cómo', 'cuándo', 'dónde', 'por qué',
    'ahora', 'entonces', 'luego', 'después', 'antes',
    'hoy', 'ayer', 'mañana',
    'ya', 'aún', 'todavía', 'apenas', 'casi',
    'solo', 'solamente', 'únicamente',

    // Question words
    'qué', 'quién', 'quiénes', 'cuál', 'cuáles',
    'cuánto', 'cuánta', 'cuántos', 'cuántas',

    // Others
    'todo', 'toda', 'todos', 'todas',
    'otro', 'otra', 'otros', 'otras',
    'algún', 'alguno', 'alguna', 'algunos', 'algunas',
    'ningún', 'ninguno', 'ninguna', 'ningunos', 'ningunas',
    'mismo', 'misma', 'mismos', 'mismas',
    'cada', 'varios', 'varias', 'ambos', 'ambas',
    'tal', 'tales', 'cual', 'cuales',
    'mucha', 'muchas', 'poca', 'pocas',
    'nueva', 'nuevo', 'nuevos', 'nuevas',
    'vieja', 'viejo', 'viejos', 'viejas',
    'buena', 'bueno', 'buenos', 'buenas',
    'mala', 'malo', 'malos', 'malas',
    'primera', 'primero', 'primeros', 'primeras',
    'última', 'último', 'últimos', 'últimas',
];
