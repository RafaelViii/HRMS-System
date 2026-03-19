# Memo System Fixes and Enhancements

## Date: 2025-11-10

## Issues Fixed

### 1. **File Preview Not Working**
**Problem**: Memo attachments were not displaying in preview mode
- Files were being stored in database (`file_content` column) but column didn't exist in schema
- Preview was trying to read from filesystem only

**Solution**:
- Created migration: `2025-11-10_memo_attachments_file_content.sql`
  - Added `file_content BYTEA` column to `memo_attachments` table
- Updated `memo_fetch_attachment()` in `form_helpers.php`:
  - Now reads `file_content` from database
  - Handles PostgreSQL BYTEA streams properly
  - Falls back to filesystem if database content is NULL
- Enhanced `preview_file.php`:
  - Tries both relative and absolute file paths
  - Better error logging for debugging

### 2. **Send To / Mention Function Not Working**
**Problem**: Recipients weren't receiving notifications when memos were posted
- Notification trigger only fired on UPDATE, not INSERT
- Memos set `published_at` during INSERT, so trigger never fired

**Solution**:
- Created migration: `2025-11-10_fix_memo_notification_trigger.sql`
- Updated `fn_notify_memo_published()` function:
  - Now handles both INSERT and UPDATE operations
  - Checks `TG_OP` to determine operation type
  - Added `u.status = 'active'` filter for recipients
- Updated trigger to fire on: `AFTER INSERT OR UPDATE ON memos`

## New Features

### 3. **Acknowledgement System**
**Feature**: Users can acknowledge receipt of memos; admins can track who has acknowledged

**Database**:
- Created migration: `2025-11-10_memo_acknowledgements.sql`
- New table: `memo_acknowledgements`
  - Columns: `id`, `memo_id`, `user_id`, `acknowledged_at`
  - Unique constraint: `(memo_id, user_id)` - one acknowledgement per user per memo
  - Indexed on `memo_id`, `user_id`, and `acknowledged_at`

**Frontend** (`modules/memos/view.php`):
- **Acknowledgement Section** (new card in sidebar):
  - Shows checkmark icon if user has acknowledged
  - "Acknowledge Receipt" button if not yet acknowledged
  - Displays total acknowledgement count with icon badge
  - **For Everyone**: Shows count of total acknowledgements
  - **For Admin/HR Only**: Expandable details section showing:
    - Full list of who acknowledged
    - Employee codes
    - Timestamps of acknowledgements
    - Collapsible with smooth animation

**Backend**:
- POST handler for acknowledgement submission
- Uses `ON CONFLICT ... DO UPDATE` to handle re-acknowledgements (updates timestamp)
- Full audit trail via `audit()` and `action_log()`
- CSRF protection

**UI Design**:
- Green checkmark icons for visual clarity
- Emerald color scheme for acknowledgement success states
- Admin details section uses `<details>` element for clean collapsing
- Scrollable list (max-height) for many acknowledgements

## Database Migrations Created

1. **`2025-11-10_memo_acknowledgements.sql`**
   - Creates `memo_acknowledgements` table
   - Indexes and foreign keys

2. **`2025-11-10_memo_attachments_file_content.sql`**
   - Adds `file_content BYTEA` column to `memo_attachments`

3. **`2025-11-10_fix_memo_notification_trigger.sql`**
   - Fixes notification trigger to fire on INSERT
   - Updates `fn_notify_memo_published()` function

## Files Modified

### Backend
- `modules/memos/view.php` - Added acknowledgement handling and display
- `modules/memos/preview_file.php` - Enhanced file reading logic
- `modules/memos/form_helpers.php` - Updated `memo_fetch_attachment()` for database content

### Database
- 3 new migration files (see above)

## Testing Checklist

### File Preview
- [x] Upload new memo with PDF attachment
- [x] Upload new memo with image attachment (PNG/JPG)
- [x] Preview displays correctly in modal
- [x] Download works when enabled
- [x] Preview-only mode works when downloads disabled

### Notifications
- [x] Create memo with "All employees" audience
- [x] Create memo with specific department
- [x] Create memo with specific role
- [x] Create memo with individual employees
- [x] Verify notifications appear in bell icon
- [x] Verify notification payload contains correct memo_id

### Acknowledgements
- [x] Employee can click "Acknowledge Receipt" button
- [x] Button changes to checkmark after acknowledgement
- [x] Count updates immediately
- [x] Admin can view detailed list of acknowledgements
- [x] List shows employee names, codes, and timestamps
- [x] Details section is collapsible
- [x] Cannot acknowledge same memo twice (unique constraint)

## How to Run Migrations

```bash
# Run all pending migrations
php tools/migrate.php
```

Or manually run each SQL file:

```bash
psql -h localhost -U your_user -d hrms -f database/migrations/2025-11-10_memo_acknowledgements.sql
psql -h localhost -U your_user -d hrms -f database/migrations/2025-11-10_memo_attachments_file_content.sql
psql -h localhost -U your_user -d hrms -f database/migrations/2025-11-10_fix_memo_notification_trigger.sql
```

## Usage

### For Employees
1. Receive memo notification
2. Click notification to view memo
3. Review memo content and attachments
4. Click "Acknowledge Receipt" button
5. Green checkmark appears confirming acknowledgement

### For Admins/HR
1. Create memo with audience selection
2. Recipients automatically receive notifications
3. View memo to see acknowledgement statistics
4. Click "View Details (Admin)" to see who acknowledged
5. Monitor compliance with acknowledgement counts

## Security Notes

- All acknowledgement actions logged via `audit()` and `action_log()`
- CSRF tokens required for all POST requests
- File content stored securely in database (BYTEA)
- Admin-only access to detailed acknowledgement list
- SQL injection prevention via prepared statements

## Performance Considerations

- Acknowledgement count query is simple COUNT, very fast
- Detailed list only loaded for admins (conditional query)
- Indexes on memo_id and user_id for fast lookups
- File content stored in database for faster access (no disk I/O)

## Future Enhancements (Optional)

1. **Acknowledgement Reminders**:
   - Send reminder notification to users who haven't acknowledged after X days
   - Configurable reminder threshold per memo

2. **Bulk Acknowledgement**:
   - Allow users to acknowledge multiple memos at once
   - Useful for catching up on missed memos

3. **Analytics Dashboard**:
   - Show acknowledgement rate per department
   - Track average time to acknowledge
   - Identify users with low acknowledgement rates

4. **Required Acknowledgement**:
   - Flag certain memos as "must acknowledge"
   - Block access to certain features until acknowledged
   - Popup reminder on login

5. **Email Notifications**:
   - Send email when memo is posted
   - Include acknowledge button in email
   - Send reminder emails for unacknowledged memos

## Support

For issues:
1. Check system logs via `sys_log()` entries
2. Check audit trail in `audit_logs` table
3. Check notification trigger status: `SELECT * FROM pg_trigger WHERE tgname LIKE 'trg_notify_%';`
4. Verify table structure: `\d memo_acknowledgements` in psql
