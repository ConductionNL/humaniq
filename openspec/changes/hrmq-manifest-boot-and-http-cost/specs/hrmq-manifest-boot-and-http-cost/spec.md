## ADDED Requirements

### Requirement: The bundled-manifest endpoint is HTTP-cacheable

`GET /apps/hrmq/api/manifest` SHALL return caching headers appropriate for a build-time-immutable
payload, and SHALL support conditional revalidation.

**Feature tier**: MVP

#### Scenario: A repeat request with a matching ETag returns 304

- GIVEN a prior `GET /apps/hrmq/api/manifest` response with an `ETag` header
- WHEN a client repeats the request with `If-None-Match` set to that ETag
- THEN the server MUST respond `304 Not Modified`
- AND MUST NOT re-send the full manifest payload

#### Scenario: The ETag changes when the app is upgraded

- GIVEN the app version changes across a deploy
- WHEN `GET /apps/hrmq/api/manifest` is called after the upgrade
- THEN the returned `ETag` MUST differ from the pre-upgrade value

### Requirement: The static bundled manifest does not pay per-boot reactive-conversion cost

The client-side bundled manifest object, being static and never mutated after import, SHALL NOT be
walked by Vue's reactivity system on every app boot.

**Feature tier**: MVP

#### Scenario: The manifest object is not deep-observed at boot

- GIVEN `src/main.js`'s app-boot sequence
- WHEN the root Vue instance mounts with the bundled manifest as a prop
- THEN the manifest object's nested properties MUST NOT be individually converted to Vue-reactive
  getters/setters
- AND all six manifest pages MUST continue to render and navigate correctly
