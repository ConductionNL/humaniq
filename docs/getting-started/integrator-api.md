---
sidebar_position: 3
description: How an external system reads and writes humaniq employees and contracts through OpenRegister's objects API, signed in with a service account's app password.
---

# Integrating through the objects API

humaniq has no API of its own for its HR records. An external system (a payroll
bureau, an identity manager, a reporting tool) reads and writes them through
OpenRegister's objects API, signed in as a **service account**: a Nextcloud user
that exists only for that system.

Every call gets exactly the access that account has in the app. The same role
checks, the same field access and the same administration scope apply as for a
person signed in with that account. There is no separate API permission to grant
and none to forget.

## 1. Set up the service account

1. Create a Nextcloud user for the system, for example `svc-payroll-bureau`.
2. Put it in the group that matches what the system does:
   - `humaniq-payroll` for a payroll bureau that reads employees, contracts and payslips;
   - `humaniq-hr` for a system that maintains employee records, such as an identity manager.
   An account in neither group reads no employee.
3. Give it a role in the administration it works for, as you would a person.
4. Signed in as that user, create an **app password** under *Personal settings → Security*.
   Give the system the user name and that app password. Never hand out the account's own password.

## 2. Make the calls

The base URL is `https://<your-nextcloud>/apps/openregister/api/objects/humaniq`.
Every call uses HTTP Basic authentication with the user name and the app password,
and sends `OCS-APIRequest: true`.

| What | Call |
| --- | --- |
| List employees | `GET /Employee` |
| Read one employee | `GET /Employee/{id}` |
| Change some fields of an employee | `PATCH /Employee/{id}` |
| Create an employee | `POST /Employee` |
| List an employee's contracts | `GET /EmploymentContract?employeeId={id}` |
| Read one contract | `GET /EmploymentContract/{id}` |
| Who changed a record, and when | `GET /Employee/{id}/audit-trails` |

### Read an employee

```bash
curl -u svc-payroll-bureau:APP-PASSWORD \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  https://cloud.example.org/apps/openregister/api/objects/humaniq/Employee/0b6f3c1e-...
```

The answer holds the fields the account's role may read. A payroll account sees the
salary fields; a field its role may not read is left out, as it would be on screen.

### Change a field

```bash
curl -u svc-identity:APP-PASSWORD -X PATCH \
  -H 'OCS-APIRequest: true' -H 'Content-Type: application/json' \
  -d '{"phone": "+31 20 123 4567"}' \
  https://cloud.example.org/apps/openregister/api/objects/humaniq/Employee/0b6f3c1e-...
```

`PATCH` changes only the fields you send. The change is in the record's audit
trail under the service account's name, so you can always see which system made it.
humaniq's own checks run on the write as for a person: a field the account may not
change, or a value a rule refuses, is refused with a message.

### Read the contracts of an employee

```bash
curl -u svc-payroll-bureau:APP-PASSWORD \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  'https://cloud.example.org/apps/openregister/api/objects/humaniq/EmploymentContract?employeeId=0b6f3c1e-...'
```

## 3. What a refusal looks like

- `401`: the user name or app password is wrong, or the app password was revoked.
- An empty list or `404`: the account may not read these records. An account in no
  humaniq group lists no employees at all.
- `403` or `422` on a write: the account may not change this field, or a rule refused the value.
  The body says which.

Revoke an app password under *Personal settings → Security* of the service account
the moment a system no longer needs it.
