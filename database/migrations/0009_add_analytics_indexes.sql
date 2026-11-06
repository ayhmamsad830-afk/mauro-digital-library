-- Phase 5 admin analytics / moderation: date-range scans without a leading book_id/user_id.
ALTER TABLE reading_sessions ADD KEY idx_reading_sessions_started (started_at);
ALTER TABLE reviews ADD KEY idx_reviews_status_created (status, created_at);
ALTER TABLE users ADD KEY idx_users_created (created_at);
