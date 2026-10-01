MealBells: Milestones Guide (1g se 1m)
Stage 1 ka baaki kaam, step-by-step. Har milestone mein: kyun, kya banana hai, rules, tests, aur "done kab".

Status abhi: Stage 0 aur Stage 1 ke 1a-1f (backend core) done. 23 tests green. Scheduler mealbells:process-cutoff har minute registered hai.

Kaam ka tareeka (har milestone par):

Pehle design padho aur khule decisions lock karo
Tests likho (red dekho)
Code likho (green)
Poori suite chalao: php artisan test
Sheet mein status Done karo
Stage 1 ka asli pass test: vendor bole "ye count dekh ke maine kam khana banaya." Wahan tak 1g-1i sabse zaroori hain.

1g: Vendor Preparation View
Kyun: Vendor ko lock hua ya estimate count dikhana. Bina iske poora engine kisi ko dikhta hi nahi.

Vendor ko kya dikhna chahiye
Aaj, 5 Oct (Mon)                 Locked 10:30 AM
KUL: 313 meals
Aaj ka menu: Chole + Rice  (Override: Rajma unavailable)

Company     Base  Skip  Extra  Final
Acme Corp   300   -17   +5     288   Locked
Beta Ltd     30    -3    0      27   Estimate
  Late change: +5 (11:05, guest) -> 32
Sections: header (date, total), aaj ka meal card, per-company table, late changes, date tabs.

Data kahan se
Situation	Source
Date locked	meal_counts snapshot + meal_count_changes (adjusted_total). Live calculation kabhi nahi
Date unlocked	CalculateExpectedMeals live, "Estimate, final nahi" label ke saath
Non-meal day	MealCalendar se "Is din meal nahi hai"
Assignment us date par nahi	Company dikhe hi nahi
Vendor ki companies: unlocked date par CompanyTiffinAssignment::activeOn($date) se. Locked date par meal_counts.tiffin_service_id se (snapshot ke waqt ka sach). tiffin_service_id = null wale snapshot vendor ko nahi dikhte.

Total ka rule: locked companies ka adjusted_total + unlocked ka live estimate. Ek bhi company unlocked ho toh total is_estimate = true.

Privacy (kabhi nahi dikhana)
Employee ke naam, codes, email
Skip ka reason
Skip ka source-wise breakdown (meri raay: vendor ko sirf total skips)
Extra meals mein sirf type (guest/visitor), reason nahi
Structure
VendorPreparationController@show   (validate + response)
        -> BuildVendorPreparationView(TiffinService, date)   (Action)
        -> array return (Inertia aur baad ki /api/v1 dono use karein)
Sub-steps
Step	Kya	Tests
1g-1	BuildVendorPreparationView Action	Neeche 1-7, 11
1g-2	Controller + route /tiffin-admin/preparation + role + date validation	8-10, 12
1g-3	Vue page: header, meal card, company table, late changes, date tabs, empty states	Page props ka ek feature test
Tests
Vendor A ko vendor B ki company ka count nahi dikhta
Locked date par snapshot dikhta hai, live nahi
Unlocked date par is_estimate = true
Post-cutoff change ke baad adjusted sahi, original final nahi badla
Non-meal day par number nahi, "meal nahi" flag
Assignment ends_at ke baad company gayab
tiffin_service_id = null snapshot vendor ko nahi dikhta
Response mein employee naam, code, skip reason kahin nahi
Company Admin us route par 403
Date range ke bahar reject (past 30 din, future 14 din)
Locked + unlocked mix mein total sahi
Vendor ka tiffin_service_id nahi hai toh khaali state
Edge cases
Koi company assigned nahi: "Abhi koi company assigned nahi hai"
Sab companies non-meal day: "Aaj kisi company ko meal nahi chahiye"
Aaj ka snapshot abhi nahi bana: live estimate
Limitation: count per day hai, per meal (breakfast/lunch/dinner) nahi. Report mein likh do.
Pehle lock karne wale decisions
Vendor ko skip breakdown ya sirf total? (suggestion: total)
Date window past 30 / future 14 theek hai?
Menu card isi screen par? (suggestion: haan)
users mein Tiffin Admin ka tiffin service se link kaise hai (column ya pivot)?
Done kab: seeded data par Tiffin Admin login kare, aaj aur kal ka count sahi dikhe, locked par Locked chip, unlocked par Estimate, late change dikhe, aur kisi response mein employee ka naam na ho.

1h: Cancel Actions + Bulk skip
Kyun: UI mein "skip hatana" ka button chahiye. Bina cancel ke HR galti se skip daal de toh hata nahi paayega.

Kya banana hai
Migration: cancelled_at, cancelled_by (skips aur meal_adjustments par, agar abhi nahi hain)
CancelSkip, CancelExtraMeal
RecordSkip cancelled row ko reactivate kare (naya row nahi, unique (employee_id, date) ki wajah se)
Bulk skip
CalculateExpectedMeals sirf cancelled_at IS NULL ginta ho
Cancel ke rules
Company match, warna reject
Pehle se cancelled ho toh error nahi, wahi row (idempotent)
Past date, cutoff nikal chuka, ya count locked ho toh reject
Row delete nahi, soft cancel
Quantity edit ka alag Action nahi. Galat ho toh cancel karke naya daalo
Bulk skip
Input: employees ki list + ek date ya range, reason
Range cap 31 din
Har employee-date par RecordSkip, result per employee: created, already_skipped, rejected (reason ke saath)
Non-meal din chupchap skip, aur result mein alag count
Poora ek transaction mein; business-rule rejects error nahi, result mein aate hain
Tests
Cancel ke baad expected wapas pehle jitna · dobara cancel par error nahi · dusri company ki skip cancel nahi hoti · cutoff/lock ke baad cancel reject · reactivate same row · bulk ke counts · bulk mein non-meal din.

Done kab: skip daalo, cancel karo, count wapas pehle jaisa, aur kuch delete nahi hua.

1i: Company Admin UI
Kyun: Abhi tak koi HR user ye sab use nahi kar sakta. Ye pura backend UI ke bina bekaar hai.

Screens
Employees: list, search, status/department filter, add/edit
CSV import: upload, preview (kitne naye, update, errors, file mein nahi wale), confirm. ValidateEmployeeCsv aur ImportEmployeeCsv se
Settings: cutoff time, timezone, wfh_auto_skip, meal_days, primary/backup admin
Skip: employee se skip daalna aur hatana, bulk skip
Extra meal: date, quantity, type, reason
Count card: aaj aur agle 7 din ka count, breakdown, locked/open status
Rules
company_id hamesha logged-in user se, request se kabhi nahi
CSV preview ke valid rows server-side cache mein, browser se wapas nahi
meal_days badalne ka asar sirf aage ki dates par
Tiffin Admin ko employees ki list nahi
Tests
Policy tests (dusri company ka admin data na dekhe, Tiffin Admin blocked) · import controller ka feature test · settings validation (meal_days khaali, 0, 8, duplicate reject).

Done kab: HR bina code ke 300 employees import kare, skip aur extra meal daale, aur count dekhe.

1j: Hardening round
Kyun: Pehli real demo se pehle gaps band karne hain. Pehle tests likho, kuch pehli baar red honge, wahi asli bugs hain.

Kaam	Kyun
Ineligible/inactive employee ki skip reject (aur calculation mein bhi na ginay)	Warna count 1 se kam aayega
Past date reject, advance limit 60 din	Typo se 2030 ki date na bane
Extra meal: upper limit 100, decimal/negative reject, invalid type	5000 ka typo vendor tak na jaye
Typed MealRuleViolation + reason code	Generic Exception ki jagah, bulk/CSV code se result banayein
Duplicate skip: pehla source jeetta hai	Abhi update hota hai, CSV (1k) se pehle badalna zaroori
PostCutoffChange: original snapshot na badle, negative total reject, Tiffin Admin entry na kar paye	Audit trail safe rahe
Carbon::setTestNow sab time tests mein, boundary 10:29 / 10:31	Flaky tests band
Do companies, alag timezone ka test	Multi-company ka asli risk
Lock check RecordSkip ke andar transaction mein	Skip aur lock ke beech race
Skip source enum ek hi jagah (migration, validation, calculation)	Mismatch na ho
Done kab: nayi tests red se green, aur apni company ke real data par ek poori dry run.

1k: Leave / WFH CSV
Kyun: HR ka roz ka input. Isse har skip haath se nahi daalni padti.

Kya banana hai
ValidateSkipCsv (preview) + ImportSkips (confirm), employee CSV jaisa pattern.

Columns: employee_code, from_date, to_date (optional), reason
Type (Leave ya WFH) upload ke waqt choose, CSV ke andar nahi
Date format: YYYY-MM-DD ya DD/MM/YYYY (template mein example do, 05/06/2026 India aur US mein alag matlab rakhta hai)
to_date blank ho toh single din
Rules
wfh_auto_skip = false par WFH CSV skip nahi banati, preview mein saaf message
Cancelled skip ko auto source (leave/wfh/recurring) wapas nahi laata
Pehle se skip hai toh already_skipped, error nahi (CSV baar-baar upload ho sake)
Non-meal din chhodo, result mein count
Row errors: unknown code, ineligible, past date, to < from, range cap se zyada
Tests
Date formats · partial errors · double upload par duplicate nahi · cancelled skip wapas nahi aati · WFH-off company · non-meal din.

Pehle zaroori: 1j ka "pehla source jeetta hai" rule.

Done kab: HR leave aur WFH CSV upload kare, preview dekhe, confirm kare, aur count sahi badle.

1l: Summary, anomaly, review
Kyun: Humne approval exception-based design kiya tha: admin ko sirf tab pareshan karna jab zarurat ho.

Flow
Cutoff - 30 min   Draft row + summary primary admin ko ("Aaj 288 meals, kal jaisa")
                  Anomaly ho toh alert saath mein
Cutoff - 15 min   Anomaly hai aur review nahi hua -> backup admin ko alert
Cutoff            Lock (review ho ya na ho)
Cutoff            Tiffin Admin ko "count ready"
Rules
Review sirf "maine dekh liya" hai, lock nahi. Lock hamesha cutoff par
Summary sirf ek baar jaye (summary_sent_at)
Primary/backup set nahi hai toh sab company admins ko bhejo
Anomaly sirf flag hai, lock nahi rukta
Anomaly rules (sirf 3)
Rule	Kab
Count bahut alag	Pichhle 5 locked meal-days ke average se 20% zyada fark
Spike	Extra limit se zyada, ya skips base ke 30%+
Zero	Final 0 ya base 0
Kam history par alert nahi: 3 se kam locked snapshots hain toh "count alag" rule off, warna naye company mein false alarm se admin alerts ignore karna seekh lega.

Tests
Summary ek baar · review count ko lock nahi karta · backup sirf anomaly + no-review par · kam history par flag nahi · non-meal day par kuch nahi · tiffin assign nahi toh alert.

Done kab: normal din admin ko sirf chhoti summary, anomaly din alert.

1m: Production readiness
Kyun: Local par sab chalta hai, server par ye cheezein na hon toh silently kaam nahi karega.

Kaam	Detail
Cron	Har minute php artisan schedule:run
Queue worker	Notifications ke liye chalta rahe (supervisor)
Health check	Last scheduler run ka timestamp, Super Admin dashboard par
Production DB	SQLite se MySQL/Postgres. JSON default aur constraints dobara test
Shared cache	onOneServer() ke liye Redis ya database, file nahi
DB constraint	Ek company ka ek hi active assignment, DB level par
Event commit ke baad	DailyCountConfirmed afterCommit
Backups, .env, logs	Basic setup
Docs	PROJECT_REPORT.md aur report update
Local test: php artisan schedule:work

Done kab: staging par ek poora din bina haath lagaye chale (summary, lock, vendor notification).

Aage (Stage 2 se 5)
Stage	Kya	Pass test
2	Employee login, self-skip, one-tap email link, recurring skip, reminders	70-80% skip khud hon
3	Attendance/HRMS integration (POST /api/v1/attendance, API key + HMAC)	Bina haath lagaye data aaye
4	1-2 aur companies pilot, phir pricing + billing	Dusri company khud onboard ho
5	Mobile app, analytics, premium UI	Scale
Khule decisions (abhi tak tay nahi)
Override per tiffin ya per company
Extra meal kaun request kare (suggestion: sirf Company Admin)
Leave/WFH data kis form mein milta hai
Skip channel: email ya WhatsApp
Company Admin meal_days khud badle? (suggestion: haan, sirf aage ki dates par)
Primary aur backup admin kaun
Per-meal (breakfast/lunch/dinner) count Phase 2 mein?
Special Saturday aur holidays Phase 2 mein (MealCalendar ke andar)
Order yaad rakhne ke liye
1g Vendor View -> 1h Cancel + Bulk -> 1i Company Admin UI -> 1j Hardening -> 1k Leave/WFH CSV -> 1l Summary/Anomaly -> 1m Production

