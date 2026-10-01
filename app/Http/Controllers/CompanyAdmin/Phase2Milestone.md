MealBells: Phase 2 (Stage 2) Plan: Employee Side
Milestones 2a se 2h. Har milestone mein: kyun, kya banana hai, rules, tests, aur "done kab".

Phase 2 ka ek line mein matlab: Stage 1 mein sirf HR skip daalta tha. Ab employee khud apna skip kar sakega (login, one-tap link, recurring), aur humein reminders aur holiday calendar milenge. Isse HR ka roz ka kaam kam hoga.

Phase 2 ka pass test: 70-80% skips employees khud karein (source self, link, recurring), HR ko roz bolna na pade.

Shuru karne se pehle (prerequisites)
Phase 2 tabhi shuru karo jab ye ho chuka ho:

Cheez	Kyun zaroori
1g Vendor View, 1h Cancel, 1i Company Admin UI	Employee ka skip inhi Actions aur UI par tikta hai
1j Hardening (first-source-wins, typed exceptions, guards)	Employee ka self skip ko sahi rules chahiye
1k Leave/WFH CSV, 1l Summary/Anomaly	Skip ke baaki sources pehle stable ho
1m Production (queue worker, email setup, cron)	Reminders aur links email se jayenge, bina production mail ke kaam nahi karega
Stage 1 ka vendor pilot	Pehle vendor ko count se value mile, phir employee adoption par kaam karo
Milestone order
2a Employee accounts -> 2b Employee portal -> 2c Advance + recurring skip -> 2d One-tap link -> 2e Reminders + email channel -> 2f Company calendar (holidays) -> 2g Adoption metrics -> 2h Hardening + pilot

2a: Employee accounts & login
Kyun: Bina login ke employee kuch nahi kar sakta. Sabse bada sawal: email sab ke paas nahi hota (employees.email nullable hai).

Kya banana hai
users.role mein employee add, aur employees.user_id (already planned) se link
Invite flow: Company Admin employee ko invite bheje, employee password set kare
Login: role employee -> /employee/dashboard
EnsureUserRole aur policies mein employee ka scope
Login ke options (pehle decide karo)
Option	Fayda	Kami
Email + password	Simple	Sab ke paas email nahi
Employee code + password (company code ke saath)	Email nahi chahiye	Code yaad rakhna, shared code ka risk
Phone + OTP	Aasan, password nahi	SMS cost, provider chahiye
Invite link se password set + email/phone/code se login	Flexible	Thoda zyada kaam
Meri suggestion: email jahan hai wahan email login, jahan nahi wahan employee code + password. Pehla version yehi.

Rules
Invite token expiry wala aur single-use
Employee sirf apna employee record dekh sake (user_id match)
Employee inactive ho jaye toh login band, aur uski purani skips safe rahein
Ek employee ka ek hi user
Bulk invite: Company Admin poori list ko ek baar mein invite kare (CSV ke employees se)
Password reset flow (abhi nahi hai, yahan add karna padega)
Login par rate limit (already throttle:5,1)
Tests
Employee sirf apna data dekhe · dusri company ka employee login se bhi kuch na dekhe · expired/used invite reject · inactive employee login nahi · employee ko Company Admin routes par 403.

Done kab: 5 test employees invite hon, password set karein, login karke apna dashboard dekhein.

2b: Employee portal (Take / Skip)
Kyun: Ye Phase 2 ka main screen hai. Default "Take" hai, employee sirf skip karta hai.

Screen
Aaj, 5 Oct (Mon)
Meal: Chole + Rice  (Override: Rajma unavailable)
Status: Aap khana lenge     [Skip karo]
Cutoff: 10:30 AM (1 ghanta 20 min baaki)

Kal (Tue): Dal Chawal     [Skip karo]
Is hafte ka menu ...
Meri skips: 7 Oct (WFH), 12 Oct (khud)     [Hatao]
Kya banana hai
Employee dashboard: aaj ka meal (ResolveMealForDate), status (Take/Skipped), cutoff countdown
Skip button: RecordSkip source self
"Skip hatao": CancelSkip (sirf apni, sirf cutoff se pehle)
Agle 7 din ki list, apni skip history
Locked ho gaya toh buttons band aur saaf message: "Count lock ho chuka hai, HR se baat karein"
Rules
Employee sirf apne employee record par kaam kare
Wahi MealGuard rules (cutoff, locked, past, advance, non-meal day)
Employee ko doosron ki skips ya count nahi dikhna chahiye
Skip ka source self. Agar HR ne pehle skip daal di ho toh employee ko "HR ne skip kiya hai" dikhe aur wo hata na sake (first-source-wins ka rule)
Employee ko "WFH hai par khana chahiye" ka option: HR ke WFH auto-skip ko override kar sake (decision neeche)
Friendly messages (reason code se), raw error kabhi nahi
Tests
Employee apna skip kare/hataye · doosre ka nahi · cutoff ke baad reject · locked par reject · HR ki skip employee na hataye · non-meal din par button nahi.

Done kab: employee login karke aaj ka meal dekhe aur skip kare, aur HR ke count mein wo skip dikhe.

2c: Advance skip + recurring skip
Kyun: Kai logon ka pattern fixed hota hai (WFH din, vrat). Roz skip karna friction hai.

Kya banana hai
Advance skip: ek baar mein kai din ("kal se Friday tak"), cap 60 din (config)
Recurring rule: "har Friday skip", recurring_skips table (employee_id, weekday, starts_on, ends_on nullable, active)
Generator job: raat ko aage ke N din (jaise 7) ke liye recurring skips create kare, source recurring
Employee kisi ek din ke liye recurring skip hata sake ("is Friday khana chahiye")
Rules
Generated skip normal skip hi hai (RecordSkip, source recurring), toh sab guards lagenge
Employee ne ek din manually cancel kiya toh generator use dobara na banaye (first-source-wins ka rule: cancelled skip ko auto source wapas nahi laata, 1j mein tay hua)
Non-meal day aur holiday par generator kuch nahi banata
Rule delete/pause karne par aage ki generated skips (jo unlocked hain) hat jayein, past ki nahi
Generator idempotent: do baar chale toh duplicate nahi
Tests
Generator sahi dinon par skip banata hai · do baar chalao toh duplicate nahi · cancelled din dobara nahi banta · rule pause par future skips hatein · locked din par kuch nahi · non-meal day chhoda.

Done kab: "har Friday skip" lagao, agle 4 Friday ke skips apne aap bane hon aur count mein dikhein.

2d: One-tap link (email)
Kyun: Login ke bina skip. Employee ko app kholna hi nahi, sirf email mein ek click.

Flow
Raat ko email: "Kal ka meal: Rajma Rice. [Skip karo]"
        |
Click karte hi (confirm page par ek button) -> skip ho jaye -> "Ho gaya"
Kya banana hai
skip_tokens table: employee_id, date, token_hash, expires_at, used_at
Signed, expiry wala link (cutoff tak valid)
Confirm page (GET sirf page dikhaye, POST skip kare)
"Skip hatao" link bhi (undo)
Source link
Zaroori security / design rules
Email scanners aur link previewers GET link khol dete hain. Agar GET se skip ho gaya toh bina click ke skip ban jayega. Isliye GET sirf confirm page dikhaye, asli skip POST se
Token sirf hash karke store karo, ek employee + ek date ke liye hi valid
Single-use (ya clearly idempotent), cutoff ke baad expire
Rate limit
Token se sirf us date ka skip ho sakta hai, aur kuch nahi (login nahi milta)
Wahi MealGuard rules, locked/cutoff par friendly page ("Ab skip nahi ho sakta")
Tests
Valid token skip banata hai · GET se skip nahi banta · expired/used token reject · token doosre employee ya date par nahi chalta · cutoff ke baad reject · rate limit.

Done kab: email mein link khol ke skip ho jaye, bina login ke, aur company count mein dikhe.

2e: Reminders + notification channels
Kyun: Adoption reminder se aata hai. Aur ye notification system ka pehla employee-facing use hai.

Kya banana hai
Email channel (Laravel Notification + queue), template (Hinglish/English)
Evening reminder: raat ko (jaise 7 PM company timezone) "Kal ka meal: ..., skip karna ho toh ek click" (2d ka link ke saath)
Cutoff reminder (optional): cutoff se 30 min pehle un logon ko jinhone abhi tak kuch nahi kiya? (decision: iski zarurat hai ya nahi)
Meal changed alert: vendor ne override kiya toh employees ko bhi bata do (ya sirf Company Admin ko, decision)
Preferences: employee reminder band kar sake
Notification types ek jagah (mealbells.notifications config)
Rules
Non-meal day, holiday, ya jis employee ne already skip kiya uske liye reminder nahi
Reminder idempotent (do baar na jaye), sent_at track
Email na ho toh us employee ke liye reminder nahi (login ke andar in-app dikhega)
Bulk bhejna queue se, chhote batches mein, provider rate limit ka dhyan
Unsubscribe / opt-out hamesha
Tests
Reminder sirf eligible, bina skip wale ko · non-meal day par nahi · do baar chalane par ek hi · opt-out respect · email nahi toh skip · timezone ke hisaab se sahi samay.

Done kab: raat ko 10 test employees ko reminder mile, link se skip ho, aur subah count mein dikhe.

2f: Company calendar (holidays, special days)
Kyun: Abhi sirf meal_days (weekday) hai. Company holiday par meal nahi, aur special Saturday par meal chahiye.

Kya banana hai
company_calendar_days table: company_id, date, type (holiday, working_day), note
MealCalendar::isMealDay isse padhe: pehle date-override, phir meal_days
Company Admin UI: calendar (holiday/special working day add/hatao)
Bulk: poore saal ke holidays CSV se
Rules
Sirf MealCalendar ke andar badlav. Baaki code (scheduler, Actions, vendor view, generator) ko kuch nahi chhuna padega, kyunki sab wahi helper use karte hain
Holiday par pehle se bani skips/extra ka kya? (decision: ignore karo, count mein na ginay)
Locked date par holiday add/remove nahi
Aage ke din par holiday lage toh recurring/generated skips ko dikhna nahi chahiye
Vendor view mein "Holiday" dikhe, "meal nahi" ki jagah
Tests
Holiday par isMealDay false · special working day par true (bhale weekend ho) · scheduler holiday par snapshot nahi banata · skip/extra holiday par reject · locked date par badlav reject.

Done kab: 15 August ko holiday mark karo, us din vendor ko "meal nahi" dikhe aur koi snapshot na bane.

2g: Adoption metrics
Kyun: Phase 2 ka pass test number se naapna hai, andaze se nahi.

Kya banana hai
Company Admin ke liye chhota report:

Skips ka source-wise split (hr, leave, wfh, self, link, recurring)
Self-service % = (self + link + recurring) / total skips
Kitne employees ne portal use kiya, kitne ne invite accept kiya
Reminder open/click rate (agar track karein)
Anomaly: jis din HR ko 20+ manual skips daalni padi
Rules
Aggregates hi dikhao, kisi individual employee ka reason ya behavior nahi
Time range filter (7/30 din)
Done kab: Company Admin dekh sake ki is hafte 75% skips employees ne khud kiye.

2h: Hardening + pilot rollout
Hardening
Employee scoping aur privacy ka poora test (koi employee kisi doosre ka data na dekhe)
Token, invite aur login ke security tests
Rate limits, reminders ki queue load
Password reset aur account recovery flow
Employee inactive/transfer hone par kya hoga
Timezone: reminder, cutoff countdown aur "aaj" sab company ke timezone mein
Pilot
Pehle 10-20 employees ke saath (ek team)
1-2 hafte dekho: invite accept %, self-skip %, galat skips, complaints
Feedback ke baad poori company
Done kab: pilot mein 70-80% skips self-service se, aur HR ko roz ka kaam kam hua.

Optional / Phase 2 ke baad
Cheez	Kab
WhatsApp link/bot (Business API, paid + approval)	Jab email se adoption kam ho
Telegram/Slack/Teams bot	Jab company us tool par ho
Per-meal count (breakfast/lunch/dinner) aur meal_types	Jab vendor ek se zyada meal deta ho
/api/v1 employee endpoints (mobile app ke liye), Sanctum	Stage 5 se pehle, Actions reuse honge
Ek vendor, kai companies: override per company	Jab dusri company aaye
Meal feedback / rating	Baad mein
Attendance/HRMS integration	Stage 3
Khule decisions (Phase 2 shuru karne se pehle)
Employee login ka tareeka: email, employee code, ya phone/OTP? (suggestion: email jahan hai, nahi toh code + password)
Employees ko invite kaun kare: Company Admin bulk invite? Ya self-signup employee code se?
Reminder channel: email se shuru, WhatsApp kab?
Reminder samay: raat 7 PM theek hai? Cutoff reminder chahiye?
WFH override: HR ne WFH CSV se skip daali, employee "khana chahiye" bol sake?
Vendor ke override par employees ko alert (ya sirf Company Admin ko)?
Holiday par pehle se bani skips ka kya (ignore)?
Employee ko apna skip history kitne din dikhe?
Order yaad rakhne ke liye
2a Accounts -> 2b Portal -> 2c Recurring -> 2d One-tap link -> 2e Reminders -> 2f Holidays -> 2g Metrics -> 2h Pilot

Pehle 2a aur 2b se employee ko ek simple dashboard do (skip karna), phir 2c-2e se friction kam karo, aur 2g-2h se adoption naapo.