-- Adds the 'mobile' analytics platform (the mobile app's sessions). Purely
-- additive: existing rows are untouched. Until it is applied, a mobile
-- session's track() call fails on insert; the app swallows that, so nothing
-- breaks, the session just is not recorded.
ALTER TABLE analytics_sessions MODIFY platform enum('kiosk','web','mobile') NOT NULL;
