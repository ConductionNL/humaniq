# performance-reviews

## ADDED Requirements

### Requirement: The employee SHALL sign off their own review (REQ-PRV-007)

After the reviewer sets a review, the employee SHALL confirm they have read it, with an optional
comment. The review SHALL record who signed and when on both sides. The employee's sign-off
SHALL NOT change the rating or the agreements.

Rows: `tal-review-forms` (humaniq matrix).

#### Scenario: The employee signs a set review
- **GIVEN** a review in status `vastgesteld` for employee Sanne
- **WHEN** Sanne signs it from `MijnBeoordelingen` with the comment "Agreed"
- **THEN** the review records her sign-off with today's date and the comment, next to the reviewer's

#### Scenario: Someone else cannot sign for the employee
- **WHEN** a user who is not the review's employee tries to sign it as the employee
- **THEN** the sign-off is refused and the review is unchanged
