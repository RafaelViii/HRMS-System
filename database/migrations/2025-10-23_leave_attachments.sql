-- Add leave request attachment support
-- Date: 2025-10-23

BEGIN;

CREATE TABLE IF NOT EXISTS leave_request_attachments (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    leave_request_id INT NOT NULL,
    document_type VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_size BIGINT NOT NULL DEFAULT 0,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_leave_attachment_request FOREIGN KEY (leave_request_id)
        REFERENCES leave_requests (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_leave_attachment_user FOREIGN KEY (uploaded_by)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_leave_attachment_request ON leave_request_attachments (leave_request_id);
CREATE INDEX IF NOT EXISTS idx_leave_attachment_uploaded_at ON leave_request_attachments (uploaded_at DESC);

COMMIT;
