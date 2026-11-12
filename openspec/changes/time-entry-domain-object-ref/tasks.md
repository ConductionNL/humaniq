## 1. Register

- [x] 1.1 Declare `domainObjectType` and `domainObjectRef` on `TimeEntry` in the shipped register with the D1 pattern, uuid format and index; bump the register version (REQ-TEC-006)
- [ ] 1.2 Verify the import on a fresh instance shows both properties on the schema

## 2. Form and timer

- [ ] 2.1 Show both fields read-only on an entry that has them; accept them as seed values in the booking dialog and the timer (REQ-TEC-007)

## 3. Guard

- [ ] 3.1 Add `tests/unit/Settings/RegisterParityTest.php`: mock `TimeEntry` properties are a subset of the shipped ones (REQ-TEC-008)
- [ ] 3.2 Run PHPUnit inside the container and confirm the test fails on the current register before 1.1 and passes after
