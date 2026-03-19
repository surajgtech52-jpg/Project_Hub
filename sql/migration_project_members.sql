-- Run this once on your database (recommended for production).
-- It matches the runtime safety-creation in db.php.

CREATE TABLE IF NOT EXISTS project_members (
  project_id INT(11) NOT NULL,
  student_id INT(11) NOT NULL,
  is_leader TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (project_id, student_id),
  KEY idx_pm_student (student_id),
  KEY idx_pm_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Optional (recommended): enforce referential integrity if your tables are InnoDB and compatible.
-- ALTER TABLE project_members
--   ADD CONSTRAINT fk_pm_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
--   ADD CONSTRAINT fk_pm_student FOREIGN KEY (student_id) REFERENCES student(id) ON DELETE CASCADE;

