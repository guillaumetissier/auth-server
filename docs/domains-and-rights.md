# Domains and rights

Optional: without domains, everything else works unchanged.

**Domains** form a tree (`a1 > a10 > a100`). A user belongs to at most one domain and never gets any
access above it or beside it: a user of `a10` may reach `a100` and `a101` if granted, never `a1` or the
sibling `a11`. Platform staff (`ROLE_ADMIN`) usually belong to no domain.

**Rights** are access expressions, held on a domain and optionally all its subdomains:

```
*:w  tire:r  invoice:-  user:r  user:w:own  @access:w
```

| Element | Meaning |
|---|---|
| `-` `r` `w` `m` | none, read, write, manage; each level includes the lower ones |
| `*` | every resource type; a named type beats `*`, whatever the order (`*:w tire:r` caps tires at read) |
| `:own` | only resources owned by the user (`user:r user:w:own`: reads every profile, edits his own) |
| `@access` `@domain` `@impersonate` | reserved system powers, never covered by `*`: manage users' rights, manage subdomains, impersonate |

Resource types are declared once (`make resource-type CODE=vehicle LABEL=Vehicles`, or the administration
pages): an expression naming an unknown type is refused.

A user's effective rights are, by domain, the highest level among: his grants, the **owner rights** of
the domains he owns (default `*:m @access:w @domain:w`, set per domain), and `ACCESS_DEFAULT_EXPRESSION`
on his own domain. They travel in the access token, and resource servers check them offline:

```json
"dom": "/a1/a10/",
"acl": [["/a1/a10/", true, "*:r vehicle:w"], ["/a1/a10/", true, "user:w:own"]]
```

```php
use GuillaumeTissier\ResourceGuard\Access\AccessLevel;

$principal->can('vehicle', AccessLevel::Write, $vehicle->domainPath);
$principal->can('user', AccessLevel::Write, $profile->domainPath, ownerId: $profile->userId);
```

Changes take effect in the user's next access token (at most `OAUTH_ACCESS_TOKEN_TTL`) and are announced
by the `user.access_changed` webhook.

## Administration pages

`/admin`, linked as **Users and rights** from the account page.

| Who | Sees | Changes |
|---|---|---|
| `ROLE_SUPER_ADMIN` | everything | everything, including who is platform administrator |
| `ROLE_ADMIN` | everything | nothing |
| Holder of `@access:r` / `@access:w` on a domain | its users and their rights | their rights and membership, **never beyond his own rights**, never his own rights nor those of the owner of his domain |
| Holder of `@domain:w` on a domain | its subdomains | creates subdomains, sets their owner and owner rights (within his own rights) |

Each user page shows the effective level per resource type, as resource servers will compute it.

## Command line

```bash
make domain                                                     # the tree
make domain CODE=a1 FLAG='--create="Group"'
make domain CODE=a10 FLAG='--create="Region 10" --parent=a1'
make domain CODE=a10 FLAG='--owner=alice --owner-rights="*:m @access:w @domain:w"'
make access USERNAME=bob FLAG=--move=a10                         # bob belongs to a10
make access USERNAME=bob DOMAIN=a10 RIGHTS="*:r vehicle:w" FLAG=--subdomains
make access USERNAME=bob                                         # shows his rights and what they give
```

## Importing from another system

Import once, then manage here:

```bash
make access-import FILE=access.json FLAG=--dry-run    # checks everything, writes nothing
make access-import FILE=access.json
```

```json
{
  "resource_types": [{"code": "vehicle", "label": "Vehicles"}],
  "domains":  [{"code": "a1", "name": "Group", "parent": null},
               {"code": "a10", "name": "Region 10", "parent": "a1", "owner": "alice", "owner_rights": "*:m @access:w @domain:w"}],
  "members":  [{"user": "alice", "domain": "a10"}, {"external_user_id": "43", "domain": "a10"}],
  "grants":   [{"user": "bob", "domain": "a10", "subdomains": true, "rights": "*:r vehicle:w"}]
}
```

Users must exist first (`make import`). The file is checked completely, then written in one transaction;
existing entries are updated, nothing is deleted.
