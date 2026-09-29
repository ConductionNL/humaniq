# Design

## Who is a project manager

humaniq models no project. Projects live in planninq (register `planninq`, schema
`project`); its `owner` is the Nextcloud user who runs the project and `members` are the
users on it. humaniq's `TimeEntry.projectId` names a planninq project by id.

The caller manages a project of the employee when a planninq project exists with
`owner` equal to the caller AND either the employee's `userId` is in its `members`, or a
humaniq `TimeEntry` of the employee carries that project's id. Both reads are system reads
(`_rbac: false`): planninq's own read rule lets only members read a project, and a project
manager does not have to be a member. When planninq is not installed nobody is a project
manager, and the endpoint behaves as before.

## What the project manager is given

The employee and the active contract are read without the caller's field access, the rate
is derived exactly as for HR, and the answer is reduced to the hourly figures. The wage
basis is left out because for an override it is HR's free-text reason; the contract and
salary never leave the service.

## Order

1. The employee is resolved under the caller's access and costed as before. A rate means
   the caller can see the salary anyway, so the full answer stands.
2. When that yields no employee (404) or no wage base (409), the project-manager check
   runs. If it holds, the reduced answer is given; otherwise the original 404 or 409.

So a caller outside HR learns nothing new about an employee they do not manage.

## The contract lookup

`findAll(['filters' => ['employee' => $id, 'status' => 'active'], 'limit' => 1])` after
`setRegister()`/`setSchema()`, as every other humaniq caller does. The test double now
declares the real signature.

## Where the code lives

`CostRateAccess` holds every read a rate is computed from: the employee and contract under the
caller's access, the planninq projects and the system reads for a project manager, and the choice
of the contract running in the period. The controller composes the answer only.
