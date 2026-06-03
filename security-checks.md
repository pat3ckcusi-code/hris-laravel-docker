# Security Checks

## Input Validation
- Leave request validation (dates, types, reasons, rejection notes)
- Employee record validation (names, email, EmpNo, designation, Dept_id, status, type, access_level, date_hired)
- Document request validation (similar to employee record)
- Color/font validation for templates
- Signatories validation (name, designation, font, size, color)

## Authorization
- Department-level authorization
- Role-based authorization (Records Manager, HR Manager, Front Desk, Leave Manager)
- Request ownership checks

## Sanitization & Encoding
- HTML escaping in mail bodies/views
- JSON handling with error checks
- String trimming
- Database null handling

## Logging
- Informational logging (approvals, actions)
- Error logging
- Printing workflow logging

## Transaction Safety
- Multi-step operations with DB transactions
- Schema checking
- Query safety (parameterized queries)
- Data integrity (balance snapshots, duplicate detection, status validation)

## Email Security
- No user-controlled headers
- Safe content generation
- Queue safety for delivery
