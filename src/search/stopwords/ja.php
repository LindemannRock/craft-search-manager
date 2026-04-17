<?php

/**
 * Japanese stop words for Search Manager search indexing
 *
 * Japanese is unusual among the shipped languages — written Japanese doesn't
 * use whitespace between words, and the built-in Tokenizer splits only on
 * whitespace and punctuation. That means stop-word filtering helps mainly for:
 *
 *  1. Space-separated query terms, which is how search UIs typically prompt
 *     users in Japanese (e.g. `東京 から 大阪`).
 *  2. Mixed Latin+Japanese content where Japanese function words happen to
 *     appear as standalone tokens after punctuation splits.
 *
 * For full Japanese morphological segmentation you'd need a dedicated CJK
 * tokenizer (MeCab, Kuromoji, Sudachi, etc.) — which the plugin does not
 * currently ship. The stop word list below still provides value without
 * full segmentation but does not replace a proper tokenizer.
 *
 * Requires normalization that preserves Japanese dakuten (U+3099) and
 * handakuten (U+309A) — added in 5.44.0. Earlier versions would mangle
 * voiced kana (で → て, が → か, ぱ → は) before filtering ever ran.
 *
 * @since 5.44.0
 */

return [
    // Particles (助詞) — grammatical function markers
    'は', 'が', 'を', 'に', 'で', 'と', 'の', 'も',
    'へ', 'から', 'まで', 'や', 'か', 'ね', 'よ', 'ば',
    'な', 'わ', 'ぞ', 'ぜ', 'さ',
    'だけ', 'ほど', 'くらい', 'ぐらい', 'ばかり',
    'とか', 'など', 'について', 'による', 'により',
    'という', 'といった',

    // Copula (である / だ) and its forms
    'だ', 'です', 'である', 'であり', 'でした', 'でしょう',
    'じゃ', 'じゃない', 'ではない', 'でない',

    // Polite auxiliaries
    'ます', 'ません', 'ました', 'ましょう', 'ませ',

    // Very-high-frequency verbs (function-like in most contexts)
    'する', 'した', 'して', 'される', 'された', 'されて',
    'なる', 'なった', 'なって', 'なり',
    'ある', 'あった', 'あり', 'ありません',
    'いる', 'いた', 'いて', 'いない',
    'ない', 'なく',

    // Demonstratives (こそあど)
    'これ', 'それ', 'あれ', 'どれ',
    'この', 'その', 'あの', 'どの',
    'ここ', 'そこ', 'あそこ', 'どこ',
    'こちら', 'そちら', 'あちら', 'どちら',
    'こう', 'そう', 'ああ', 'どう',

    // Interrogatives
    '何', 'なに', '誰', 'だれ', 'いつ', 'なぜ',

    // Common function / formal-nouns
    'こと', 'もの', 'ため', 'とき', 'ところ',
    'よう', 'ほう', 'わけ', 'はず', 'つもり',

    // Conjunctions / connectives
    'そして', 'しかし', 'でも', 'また', 'ただ',
    'ただし', 'さらに', 'つまり', 'なお',
    'ところが', 'ところで',
];
