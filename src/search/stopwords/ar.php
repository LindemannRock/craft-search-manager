<?php

/**
 * Arabic stop words for Search Manager search indexing
 *
 * Generic Arabic stop words that work across all regional dialects (MSA - Modern Standard Arabic)
 * Covers ar-SA (Saudi Arabia), ar-EG (Egypt), ar-AE (UAE), ar-JO (Jordan), etc.
 *
 * For regional customization:
 * 1. Copy to: config/search-manager/stopwords/ar-sa.php
 * 2. Add region-specific colloquialisms
 *
 * @since 5.0.0
 */

return [
    // Articles and Particles
    'ال',        // the
    'في',        // in
    'من',        // from/of
    'إلى',       // to
    'على',       // on/upon
    'عن',        // about
    'مع',        // with
    'أن',        // that
    'هذا',       // this (masculine)
    'هذه',       // this (feminine)
    'ذلك',       // that (masculine)
    'تلك',       // that (feminine)
    'هذان',      // these (dual masculine)
    'هاتان',     // these (dual feminine)
    'أولئك',     // those
    'هؤلاء',     // these (plural)

    // Pronouns
    'أنا',       // I
    'أنت',       // you (masculine)
    'أنتِ',      // you (feminine)
    'أنتم',      // you (plural masculine)
    'أنتن',      // you (plural feminine)
    'هو',        // he
    'هي',        // she
    'هم',        // they (masculine)
    'هن',        // they (feminine)
    'نحن',       // we

    // Conjunctions
    'و',         // and
    'أو',        // or
    'لكن',       // but
    'لكنّ',      // but (emphatic)
    'بل',        // rather
    'ف',         // so/then
    'ثم',        // then

    // Verbs (common auxiliary)
    'كان',       // was
    'كانت',      // was (feminine)
    'كانوا',     // were
    'يكون',      // to be
    'تكون',      // to be (feminine)
    'ليس',       // is not
    'ليست',      // is not (feminine)
    'ليسوا',     // are not
    'كل',        // all/every
    'بعض',       // some
    'أي',        // any
    'كلا',       // both
    'كلتا',      // both (feminine)

    // Prepositions
    'عند',       // at/with
    'لدى',       // at/with
    'قبل',       // before
    'بعد',       // after
    'أمام',      // in front of
    'خلف',       // behind
    'فوق',       // above
    'تحت',       // under
    'بين',       // between
    'ضد',        // against
    'حول',       // around

    // Question words
    'ما',        // what
    'ماذا',      // what
    'من',        // who
    'متى',       // when
    'أين',       // where
    'كيف',       // how
    'لماذا',     // why
    'كم',        // how much/many
    'أي',        // which

    // Common words
    'قد',        // may/might
    'لم',        // did not
    'لن',        // will not
    'لا',        // no/not
    'نعم',       // yes
    'إن',        // if/indeed
    'إذا',       // if
    'لو',        // if (hypothetical)
    'حتى',       // until/even
    'منذ',       // since
    'عندما',     // when
    'بينما',     // while
    'لأن',       // because
    'كي',        // in order to
    'حيث',       // where/whereas
    'إذن',       // then/therefore
    'أيضا',      // also
    'أيضاً',     // also (alternate)
    'فقط',       // only
    'غير',       // other than
    'سوى',       // except
    'إلا',       // except
    'بدون',      // without
    'مثل',       // like
    'كما',       // as
    'هنا',       // here
    'هناك',      // there
    'الآن',      // now
    'اليوم',     // today
    'غداً',      // tomorrow
    'أمس',       // yesterday
    'دائماً',    // always
    'أبداً',     // never
    'ربما',      // maybe
    'جداً',      // very
    'جدا',       // very (alternate)
    'كثيراً',    // much/many
    'قليلاً',    // a little
    'أكثر',      // more
    'أقل',       // less
    'كبير',      // big
    'صغير',      // small
    'جديد',      // new
    'قديم',      // old
    'أول',       // first
    'آخر',       // last/other
];
