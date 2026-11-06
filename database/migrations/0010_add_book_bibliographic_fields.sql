-- Bilingual presentation + bibliographic record for published books.
-- Additive only: every column is nullable, existing rows are untouched.
--   title_en        English title shown under the original title (e.g. "Sitting in the Darkness")
--   description_en  English description shown on the English interface
--   edition         Edition as printed in the book (e.g. "الطبعة الأولى")
--   isbn            ISBN as printed (digits and hyphens, ISBN-10 or ISBN-13)
ALTER TABLE books ADD COLUMN title_en VARCHAR(255) NULL AFTER title;
ALTER TABLE books ADD COLUMN description_en MEDIUMTEXT NULL AFTER description;
ALTER TABLE books ADD COLUMN edition VARCHAR(100) NULL AFTER publisher;
ALTER TABLE books ADD COLUMN isbn VARCHAR(20) CHARACTER SET ascii NULL AFTER edition;
