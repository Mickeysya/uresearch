# Test accounts

Every account in the local database, so you can sign in as any of them without
opening phpMyAdmin. **The password is `password` for all of them**, set by
`DatabaseSeeder::PASSWORD`. Sign in at <http://localhost:8000/login>.

This file is a snapshot of the seeded database — 52 accounts at the time of
writing. See **Regenerating this file** at the bottom. `README.md` keeps the
short version: the handful of logins a walkthrough needs.

---

## The roster — one login per role

From `DatabaseSeeder`, recreated by `./setup.sh`, `./sync.sh` and
`php artisan db:seed`. Start here.

| Email | Role | Name | Department |
|---|---|---|---|
| `student@utp.edu.my` | Student | Ahmad Danial | Computing |
| `student2@utp.edu.my` | Student | Nur Farah Adilah | Computing |
| `supervisor@utp.edu.my` | Supervisor | Dr. Aisyah Rahman | Computing |
| `chair@utp.edu.my` | Chair | Dr. Lim Wei Chun | Computing |
| `cgs@utp.edu.my` | Non-Executive CGS | M Syahmi Ifwat M Jafri | CGS |
| `seniorexec@utp.edu.my` | Senior Executive CGS | Puan Waheeda | CGS |
| `manager@utp.edu.my` | Manager CGS | Norshahirah | CGS |
| `director@utp.edu.my` | Senior Director CGS | En Zulkifly | CGS |
| `dean@utp.edu.my` | Dean of PGR | Prof. Dr. Hafiz Osman | PGR |
| `ae@utp.edu.my` | Academic Executive | Siti Academic Exec | Computing |
| `faculty@utp.edu.my` | Faculty | Faculty Office | Faculty of Engineering |
| `registry@utp.edu.my` | Registry | Registry Officer | Registry |
| `admin@utp.edu.my` | Admin | System Admin | — |

### Walking a chain end to end

- **Local travel** (two stages) — `student@` files it, `supervisor@` endorses,
  `chair@` approves. Done.
- **International travel** (four stages) — same start, then `cgs@` reviews and
  `dean@` gives final approval.
- **GA Extension** — `student@` files, `supervisor@` endorses, `cgs@` verifies,
  `director@` approves.
- **Examiner nomination** — `supervisor@` nominates for a candidate, that
  candidate's own Academic Executive approves (`ae@` for Computing, otherwise
  the one for their department below), `seniorexec@` compiles the faculty
  list, `cgs@` releases it.
- **RPD dismissal** — `cgs@` opens it, `dean@` endorses, `faculty@` signs,
  `registry@` closes the candidacy.

---

## Academic Executives — one per department

The examiner-nomination queue is department-scoped: each of these sees only
their own department's rows, so approving a nomination means signing in as the
one for *that candidate's* department. Seeded by `DatabaseSeeder`; the address
pattern is `ae.<department>@utp.edu.my`.

| Email | Name | Department | Faculty |
|---|---|---|---|
| `ae.applied@utp.edu.my` | Puan Vimala Krishnan | Applied Sciences | FSMC |
| `ae.chemical@utp.edu.my` | Puan Hafizah Malik | Chemical Engineering | FOE |
| `ae.civil@utp.edu.my` | En Khairul Anuar | Civil & Environmental Engineering | FOE |
| `ae@utp.edu.my` | Siti Academic Exec | Computing | FSMC |
| `zulhilmi.rahman@utp.edu.my` | Dr. Zulhilmi Rahman | Electrical & Electronics Engineering | FOE |
| `ae.electrical@utp.edu.my` | Puan Suraya Ismail | Electrical & Electronics Engineering | FOE |
| `ae.foundation.business@utp.edu.my` | Puan Raihanah Mokhtar | Foundation in Business Management & Computing | CFS |
| `ae.foundation.engineering@utp.edu.my` | En Faizal Ramli | Foundation in Engineering & Science | CFS |
| `ae.geosciences@utp.edu.my` | En Amirul Hakim | Geosciences | FSMC |
| `ae.integrated@utp.edu.my` | En Yusri Abdullah | Integrated Engineering | FOE |
| `ae.management@utp.edu.my` | Puan Lee Siew Mei | Management | FSMC |
| `ae.mechanical@utp.edu.my` | En Danial Hakimi | Mechanical Engineering | FOE |
| `ae.petroleum@utp.edu.my` | Puan Norazlina Samad | Petroleum Engineering | FOE |

Electrical & Electronics Engineering has two on purpose — `ae.electrical@`
from the roster and `zulhilmi.rahman@` from `DemoDataSeeder` — because a
department is allowed more than one, and a queue with two people in it is
worth being able to see. Computing's is `ae@utp.edu.my`, the original.

---

## Other staff

Extra supervisors, chairs and approvers from `DemoDataSeeder`
(`php artisan db:seed --class=DemoDataSeeder`), so a queue has more than one
person who can clear it.

| Email | Role | Name | Department |
|---|---|---|---|
| `chair-electrical-electronics-engineering@utp.edu.my` | Chair | Chair, Electrical & Electronics Engineering | Electrical & Electronics Engineering |
| `meiling.tan@utp.edu.my` | Chair | Dr. Mei Ling Tan | Chemical Engineering |
| `farid.kamal@utp.edu.my` | Supervisor | Dr. Farid Kamal | Chemical Engineering |
| `siti.nurhaliza@utp.edu.my` | Supervisor | Dr. Siti Nurhaliza | Electrical & Electronics Engineering |
| `kumaran.vellu@utp.edu.my` | Supervisor | Prof. Dr. Kumaran Vellu | Computing |

---

## Students

`student@` and `student2@` are the roster's two, from `DatabaseSeeder`. The
`2200101x`–`2200102x` cohort is `DemoDataSeeder`'s, spread across three
departments and four supervisors so the supervisor and chair dashboards have
something real to show. The `2200331x` cohort predates both and **no current
seeder recreates it** — those six live in this database only, so treat them as
disposable.

| Email | Matric | Name | Programme | Department | Supervisor |
|---|---|---|---|---|---|
| `student@utp.edu.my` | 22001001 | Ahmad Danial | MSc Full-Time | Computing | Dr. Aisyah Rahman |
| `student2@utp.edu.my` | 22001002 | Nur Farah Adilah | PhD Part-Time | Computing | Dr. Aisyah Rahman |
| `nur.aina.batrisyia@utp.edu.my` | 22001010 | Nur Aina Batrisyia | MSc Full-Time | Computing | Dr. Aisyah Rahman |
| `muhammad.haziq.irfan@utp.edu.my` | 22001011 | Muhammad Haziq Irfan | MSc Part-Time | Chemical Engineering | Dr. Farid Kamal |
| `tan.wei.jian@utp.edu.my` | 22001012 | Tan Wei Jian | PhD Full-Time | Electrical & Electronics Engineering | Dr. Siti Nurhaliza |
| `divya.sri.kumar@utp.edu.my` | 22001013 | Divya Sri Kumar | PhD Part-Time | Computing | Prof. Dr. Kumaran Vellu |
| `muhammad.amirul.aiman@utp.edu.my` | 22001014 | Muhammad Amirul Aiman | MSc Full-Time | Chemical Engineering | Dr. Aisyah Rahman |
| `lee.xin.yi@utp.edu.my` | 22001015 | Lee Xin Yi | MSc Part-Time | Electrical & Electronics Engineering | Dr. Farid Kamal |
| `nurul.iman.shafiqah@utp.edu.my` | 22001016 | Nurul Iman Shafiqah | PhD Full-Time | Computing | Dr. Siti Nurhaliza |
| `balamurugan.raj@utp.edu.my` | 22001017 | Balamurugan Raj | PhD Part-Time | Chemical Engineering | Prof. Dr. Kumaran Vellu |
| `siti.khadijah.yusof@utp.edu.my` | 22001018 | Siti Khadijah Yusof | MSc Full-Time | Electrical & Electronics Engineering | Dr. Aisyah Rahman |
| `ong.chee.kiat@utp.edu.my` | 22001019 | Ong Chee Kiat | MSc Part-Time | Computing | Dr. Farid Kamal |
| `aishah.humaira@utp.edu.my` | 22001020 | Aishah Humaira | PhD Full-Time | Chemical Engineering | Dr. Siti Nurhaliza |
| `ravindran.subramaniam@utp.edu.my` | 22001021 | Ravindran Subramaniam | PhD Part-Time | Electrical & Electronics Engineering | Prof. Dr. Kumaran Vellu |
| `farhana.izzati@utp.edu.my` | 22001022 | Farhana Izzati | MSc Full-Time | Computing | Dr. Aisyah Rahman |
| `chong.mei.xuan@utp.edu.my` | 22001023 | Chong Mei Xuan | MSc Part-Time | Chemical Engineering | Dr. Farid Kamal |
| `ahmad.zaki.hilmi@utp.edu.my` | 22001024 | Ahmad Zaki Hilmi | PhD Full-Time | Electrical & Electronics Engineering | Dr. Siti Nurhaliza |
| `priya.dharshini@utp.edu.my` | 22001025 | Priya Dharshini | PhD Part-Time | Computing | Prof. Dr. Kumaran Vellu |
| `siti.22003311@utp.edu.my` | 22003311 | Siti Nur Aina Binti Roslan | MSc Full-Time | Computing | Dr. Aisyah Rahman |
| `muhammad.22003312@utp.edu.my` | 22003312 | Muhammad Izzat Bin Hamzah | PhD Full-Time | Computing | Dr. Aisyah Rahman |
| `nurul.22003313@utp.edu.my` | 22003313 | Nurul Hidayah Binti Salleh | PhD Part-Time | Computing | Dr. Aisyah Rahman |
| `tan.22003314@utp.edu.my` | 22003314 | Tan Wei Ming | MSc Full-Time | Computing | Dr. Aisyah Rahman |
| `arvind.22003315@utp.edu.my` | 22003315 | Arvind Rajendran | PhD Full-Time | Computing | Dr. Aisyah Rahman |
| `farah.22003316@utp.edu.my` | 22003316 | Farah Nabila Binti Kamal | MSc Part-Time | Computing | Dr. Aisyah Rahman |

---

## Regenerating this file

The tables are read straight out of the database, so re-run this after
seeding, after adding accounts through **Users and Roles**, or after a
`./sync.sh`:

```bash
./vendor/bin/sail artisan tinker --execute '
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
foreach (User::orderBy("role")->orderBy("name")->get() as $u) {
    echo implode(" | ", [$u->email, Role::label($u->role), $u->name, $u->department ?: "-"]).PHP_EOL;
}'
```

To get back to exactly the roster above: `php artisan migrate:fresh --seed`.
It drops every table, so everything filed in the portal goes with it.
