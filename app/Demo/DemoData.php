<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * ============================================================================
 *  FICTIONAL DESIGN-PREVIEW DATA — NOT REAL BOOKS, AUTHORS, USERS OR STATISTICS
 * ============================================================================
 * Used only when APP_DEMO_UI=true and APP_ENV != production (config/ui.php).
 * Every title, author, review and number below is invented for layout review.
 * It is never written to the database and is never shown in production.
 * Remove this directory once real catalogue data is available if desired.
 */
final class DemoData
{
    /** @return list<array<string,mixed>> */
    public static function categories(): array
    {
        return [
            ['id' => 1, 'slug' => 'literature', 'name_ar' => 'الأدب', 'name_en' => 'Literature', 'description_ar' => 'روايات وقصص وشعر.', 'description_en' => 'Novels, short stories and poetry.', 'tone' => 1],
            ['id' => 2, 'slug' => 'history', 'name_ar' => 'التاريخ', 'name_en' => 'History', 'description_ar' => 'دراسات وسير وأحداث.', 'description_en' => 'Studies, biographies and events.', 'tone' => 2],
            ['id' => 3, 'slug' => 'science', 'name_ar' => 'العلوم', 'name_en' => 'Science', 'description_ar' => 'علوم طبيعية وتطبيقية مبسطة.', 'description_en' => 'Accessible natural and applied sciences.', 'tone' => 3],
            ['id' => 4, 'slug' => 'philosophy', 'name_ar' => 'الفلسفة', 'name_en' => 'Philosophy', 'description_ar' => 'أفكار ومدارس ومقالات.', 'description_en' => 'Ideas, schools and essays.', 'tone' => 4],
            ['id' => 5, 'slug' => 'children', 'name_ar' => 'كتب الأطفال', 'name_en' => "Children's books", 'description_ar' => 'قصص مصورة وقراءات أولى.', 'description_en' => 'Picture stories and first readers.', 'tone' => 5],
            ['id' => 6, 'slug' => 'technology', 'name_ar' => 'التقنية', 'name_en' => 'Technology', 'description_ar' => 'برمجة وحوسبة وإنترنت.', 'description_en' => 'Programming, computing and the web.', 'tone' => 6],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function authors(): array
    {
        return [
            ['id' => 1, 'slug' => 'salma-al-haddad', 'name' => 'سلمى الحداد'],
            ['id' => 2, 'slug' => 'yusuf-benali', 'name' => 'يوسف بنعلي'],
            ['id' => 3, 'slug' => 'nora-castell', 'name' => 'Nora Castell'],
            ['id' => 4, 'slug' => 'karim-al-tazi', 'name' => 'كريم التازي'],
            ['id' => 5, 'slug' => 'amal-rahmouni', 'name' => 'أمل الرحموني'],
            ['id' => 6, 'slug' => 'daniel-whitmore', 'name' => 'Daniel Whitmore'],
            ['id' => 7, 'slug' => 'layla-mansour', 'name' => 'ليلى منصور'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function books(): array
    {
        $b = static fn (int $id, string $slug, string $title, string $lang, array $authors, array $cats, int $year, int $pages, int $reads, float $rating, int $ratings, string $published, string $desc, bool $featured = false, string $rights = 'licensed') => [
            'id' => $id, 'slug' => $slug, 'title' => $title, 'language' => $lang,
            'author_ids' => $authors, 'category_ids' => $cats,
            'publisher' => $lang === 'ar' ? 'دار المثال للنشر (تجريبي)' : 'Sample House (demo)',
            'publication_year' => $year, 'page_count' => $pages,
            'reads_count' => $reads, 'rating_avg' => $rating, 'rating_count' => $ratings,
            'published_at' => $published, 'description' => $desc,
            'is_featured' => $featured, 'visibility' => 'public', 'rights_status' => $rights,
            'license_name' => $rights === 'public_domain' ? null : 'Demo License',
            'cover_url' => null, 'cover_tone' => ($id % 8) + 1,
        ];

        return [
            $b(1, 'gardens-of-the-quiet-river', 'حدائق النهر الهادئ', 'ar', [1], [1], 2021, 248, 1840, 4.6, 128, '2026-10-28',
                'رواية عن عائلة تعيد بناء بيتها القديم على ضفاف نهر صغير، وتكتشف في رسائل الجدّة حكاياتٍ عن الصبر والذاكرة والانتماء.', true),
            $b(2, 'the-cartographers-notebook', "The Cartographer's Notebook", 'en', [3], [2, 1], 2019, 312, 1320, 4.4, 96, '2026-10-21',
                'A young mapmaker inherits an unfinished atlas and follows its margins across three centuries of trade routes and forgotten ports.'),
            $b(3, 'light-and-the-small-things', 'الضوء والأشياء الصغيرة', 'ar', [4], [3], 2022, 186, 990, 4.7, 74, '2026-10-30',
                'مدخل مبسّط إلى البصريات: كيف ينكسر الضوء، ولماذا تبدو السماء زرقاء، وكيف تعمل العدسات من حولنا.'),
            $b(4, 'cities-of-salt-and-ink', 'مدن الملح والحبر', 'ar', [2], [2], 2018, 402, 760, 4.2, 51, '2026-09-14',
                'دراسة تاريخية لموانئ المتوسط بين القرنين الخامس عشر والثامن عشر من خلال سجلات التجار ورسائلهم.'),
            $b(5, 'thinking-in-questions', 'التفكير بالأسئلة', 'ar', [5], [4], 2020, 220, 1210, 4.5, 88, '2026-10-05',
                'مقالات قصيرة تدرّب القارئ على طرح أسئلة أفضل قبل البحث عن الإجابات، مع أمثلة من الحياة اليومية.'),
            $b(6, 'the-patient-machine', 'The Patient Machine', 'en', [6], [6], 2023, 276, 1560, 4.3, 102, '2026-10-25',
                'An approachable history of computing told through the people who taught machines to wait, repeat and remember.'),
            $b(7, 'the-moon-who-lost-her-shoe', 'القمر الذي أضاع حذاءه', 'ar', [7], [5], 2024, 32, 2310, 4.9, 154, '2026-11-01',
                'قصة مصوّرة للأطفال عن قمرٍ صغير يبحث عن حذائه بين النجوم، ويتعلّم طلب المساعدة من أصدقائه.'),
            $b(8, 'letters-from-the-south', 'رسائل من الجنوب', 'ar', [1, 4], [1], 2017, 198, 640, 4.1, 39, '2026-08-19',
                'مجموعة رسائل متخيَّلة بين صديقين يفصل بينهما البحر، تجمع بين أدب الرحلة والتأمل.'),
            $b(9, 'small-numbers-big-ideas', 'Small Numbers, Big Ideas', 'en', [6, 3], [3], 2021, 230, 870, 4.4, 60, '2026-09-30',
                'Everyday mathematics explained with short stories: probability at the bus stop, geometry in the kitchen, and more.'),
            $b(10, 'the-house-of-wisdom-revisited', 'بيت الحكمة: قراءة جديدة', 'ar', [2], [2, 4], 2016, 356, 1110, 4.6, 83, '2026-07-22',
                'قراءة في حركة الترجمة والعلم في بغداد العباسية، وأثرها في انتقال المعرفة عبر الحضارات.', false, 'owned'),
            $b(11, 'learning-to-code-gently', 'تعلّم البرمجة بهدوء', 'ar', [5], [6], 2024, 264, 1430, 4.5, 91, '2026-10-12',
                'دليل للمبتدئين يشرح أساسيات البرمجة خطوةً خطوة، مع تمارين صغيرة وأفكار لمشاريع أولى.'),
            $b(12, 'the-quiet-orchard', 'The Quiet Orchard', 'en', [3], [1], 2015, 288, 540, 4.0, 33, '2026-06-03',
                'A novel about a family orchard, a drought summer and the neighbours who share their water.', false, 'public_domain'),
            $b(13, 'stars-for-beginners', 'النجوم للمبتدئين', 'ar', [4], [3, 5], 2023, 96, 980, 4.8, 70, '2026-10-18',
                'كتاب مصوّر يعرّف الصغار والكبار على الكواكب والنجوم والمجرّات بلغة سهلة.'),
            $b(14, 'essays-on-attention', 'Essays on Attention', 'en', [6], [4], 2022, 174, 450, 4.2, 28, '2026-05-11',
                'Short essays on focus, distraction and the ethics of designing tools that ask for our time.'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function reviews(): array
    {
        return [
            ['book_id' => 1, 'user_name' => 'قارئ تجريبي ١', 'rating' => 5, 'comment' => 'تعليق تجريبي لعرض التصميم فقط.', 'created_at' => '2026-10-29'],
            ['book_id' => 1, 'user_name' => 'Demo reader 2', 'rating' => 4, 'comment' => 'Sample review text used to preview the layout.', 'created_at' => '2026-10-30'],
        ];
    }

    /** Fictional member used only by the /account design preview. */
    public static function user(): array
    {
        return ['name' => 'مستخدم تجريبي', 'email' => 'demo@example.invalid', 'avatar_url' => null, 'role' => 'user', 'created_at' => '2026-09-01'];
    }
}
