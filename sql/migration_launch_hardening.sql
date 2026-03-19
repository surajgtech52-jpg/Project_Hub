-- Recommended production hardening migration (run once via phpMyAdmin).
-- 1) project_members table + FKs (if desired)
CREATE TABLE IF NOT EXISTS project_members (
  project_id INT(11) NOT NULL,
  student_id INT(11) NOT NULL,
  is_leader TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (project_id, student_id),
  KEY idx_pm_student (student_id),
  KEY idx_pm_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Optional foreign keys (uncomment after verifying both tables are InnoDB and ids match)
-- ALTER TABLE project_members
--   ADD CONSTRAINT fk_pm_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
--   ADD CONSTRAINT fk_pm_student FOREIGN KEY (student_id) REFERENCES student(id) ON DELETE CASCADE;

-- 2) Helpful indexes for scale
ALTER TABLE projects
  ADD INDEX idx_projects_year_archived (project_year, is_archived),
  ADD INDEX idx_projects_guide_archived (assigned_guide_id, is_archived),
  ADD INDEX idx_projects_locked (is_locked);

ALTER TABLE upload_requests
  ADD INDEX idx_upload_requests_project (project_id),
  ADD INDEX idx_upload_requests_guide (guide_id);

ALTER TABLE student_uploads
  ADD INDEX idx_student_uploads_project (project_id),
  ADD INDEX idx_student_uploads_request (request_id);

