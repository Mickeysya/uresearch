# Accounts

Every login the seeders create. **Password for all: `password`.**

| How you got your database | What you have |
|---|---|
| `./sync.sh` (any time, keeps your data) | the **257 base accounts** below |
| `./sync.sh --fresh` (wipes, then rebuilds) | the base accounts **plus 80 demo accounts** made by the module demo seeders (last section) |

Source of truth: `database/seeders/DatabaseSeeder.php` (`ACADEMIC_EXECS`, `CHAIRS`, `RESEARCH_CENTRES`, `SUPERVISORS`, `CGS_OFFICERS`, `COHORT`). Change an account there, never by hand in phpMyAdmin, or the next `./sync.sh` puts it back. Generated 2026-11-08 from a fresh seed, updated 2026-11-10 for `RESEARCH_CENTRES`; if you change the seeder, regenerate this file.

## Summary

| Role | Base | After `--fresh` |
|---|---:|---:|
| Student | 182 | 252 |
| Supervisor | 24 | 33 |
| Academic Executive | 12 | 13 |
| Chair | 12 | 12 |
| Non-Executive CGS | 5 | 5 |
| Senior Executive CGS | 4 | 4 |
| Manager CGS | 1 | 1 |
| Senior Director CGS | 1 | 1 |
| Dean of PGR | 1 | 1 |
| Faculty | 1 | 1 |
| Registry | 1 | 1 |
| Research Centre | 12 | 12 |
| Admin | 1 | 1 |
| **Total** | **257** | **337** |

## University and CGS staff

| Email | Role | Name | Department |
|---|---|---|---|
| `cgs@utp.edu.my` | Non-Executive CGS | M Syahmi Ifwat M Jafri | CGS |
| `seniorexec@utp.edu.my` | Senior Executive CGS | Puan Waheeda | CGS |
| `manager@utp.edu.my` | Manager CGS | Norshahirah | CGS |
| `director@utp.edu.my` | Senior Director CGS | En Zulkifly | CGS |
| `dean@utp.edu.my` | Dean of PGR | Prof. Dr. Hafiz Osman | PGR |
| `faculty@utp.edu.my` | Faculty | Faculty Office | Faculty of Engineering |
| `registry@utp.edu.my` | Registry | Registry Officer | Registry |
| `admin@utp.edu.my` | Admin | System Admin | – |

### CGS officers

Roles follow the CGS Staff Directory: its Senior Executives are Senior Executive CGS, its Senior Assistants Non-Executive CGS. Six are one per team member; Aqil is the directory's third Senior Executive. Core routes queues by role, so each one **sees every** queue of their role; a module that emails only its own officer configures that itself (Norhanis' `Config/cgs.php` does). `cgs@utp.edu.my` and `seniorexec@utp.edu.my` above are the general accounts for those two roles.

| Email | Name | Role | Team member's modules |
|---|---|---|---|
| `waheda.cgs@utp.edu.my` | Waheda | Senior Executive CGS | Hani — Examiner Nomination · Conflict Detection · Re-viva |
| `akmar.cgs@utp.edu.my` | Akmar | Senior Executive CGS | Haziq — GRA · GA · Stage Gates · Allowance Eligibility |
| `aqil.cgs@utp.edu.my` | Aqil | Senior Executive CGS | – |
| `norshahira.cgs@utp.edu.my` | Norshahira | Non-Executive CGS | Norhanis — Travel · Publication · Claims |
| `amirul.cgs@utp.edu.my` | Amirul | Non-Executive CGS | Chloe — Workstation · Study Candidacy |
| `zulkifly.cgs@utp.edu.my` | Zulkifly | Non-Executive CGS | Nureen — Attendance · GA Extension · Supervision · Certification |
| `syahmi.cgs@utp.edu.my` | Syahmi | Non-Executive CGS | Jason — Hardbound · Appeal Hardbound · Appointment Letters |

## Departments: Chair, Academic Executive, Research Centre and Supervisors

A Chair, an Academic Executive and a Research Centre reviewer each see **only their own department's** queue (Research Centre's is GA Application's `research_centre_interview` stage), so every department has one of each. Two supervisors per department: 24 in all.

| # | Department | Faculty | Chair | Academic Executive | Research Centre | Supervisors |
|---|---|---|---|---|---|---|
| 1 | Foundation in Business Management & Computing | CFS | Dr. Rosnah Abdul Karim<br>`chair.foundation.business@utp.edu.my` | Puan Raihanah Mokhtar<br>`ae.foundation.business@utp.edu.my` | Puan Zarina Mohd Yusof<br>`rc.foundation.business@utp.edu.my` | Dr. Hasliza Omar<br>`hasliza.omar@utp.edu.my`<br>Mr. Daniel Lee Chee Wai<br>`daniel.lee@utp.edu.my` |
| 2 | Foundation in Engineering & Science | CFS | Dr. Kamarul Ariffin<br>`chair.foundation.engineering@utp.edu.my` | En Faizal Ramli<br>`ae.foundation.engineering@utp.edu.my` | En Shahrul Nizam<br>`rc.foundation.engineering@utp.edu.my` | Dr. Suhaimi Yahya<br>`suhaimi.yahya@utp.edu.my`<br>Dr. Kalaivani Murugan<br>`kalaivani.murugan@utp.edu.my` |
| 3 | Chemical Engineering | FOE | Dr. Mei Ling Tan<br>`meiling.tan@utp.edu.my` | Puan Hafizah Malik<br>`ae.chemical@utp.edu.my` | Dr. Noraini Zalina<br>`rc.chemical@utp.edu.my` | Dr. Farid Kamal<br>`farid.kamal@utp.edu.my`<br>Dr. Lau Kok Keong<br>`lau.kokkeong@utp.edu.my` |
| 4 | Civil & Environmental Engineering | FOE | Dr. Hisham Mohamad<br>`chair.civil@utp.edu.my` | En Khairul Anuar<br>`ae.civil@utp.edu.my` | En Hafizuddin Rashid<br>`rc.civil@utp.edu.my` | Dr. Noor Azlina Hamid<br>`azlina.hamid@utp.edu.my`<br>Dr. Teo Wee Liang<br>`teo.weeliang@utp.edu.my` |
| 5 | Electrical & Electronics Engineering | FOE | Dr. Rajesh Kumar Nair<br>`chair.electrical@utp.edu.my` | Puan Suraya Ismail<br>`ae.electrical@utp.edu.my` | Puan Kalsom Ibrahim<br>`rc.electrical@utp.edu.my` | Dr. Siti Nurhaliza<br>`siti.nurhaliza@utp.edu.my`<br>Dr. Balachandran Ramasamy<br>`bala.ramasamy@utp.edu.my` |
| 6 | Integrated Engineering | FOE | Dr. Azlan Shah Ibrahim<br>`chair.integrated@utp.edu.my` | En Yusri Abdullah<br>`ae.integrated@utp.edu.my` | En Shamsul Bahrin<br>`rc.integrated@utp.edu.my` | Dr. Khairil Anwar Rosli<br>`khairil.rosli@utp.edu.my`<br>Dr. Ooi Mei Fong<br>`ooi.meifong@utp.edu.my` |
| 7 | Mechanical Engineering | FOE | Dr. Tan Boon Huat<br>`chair.mechanical@utp.edu.my` | En Danial Hakimi<br>`ae.mechanical@utp.edu.my` | Dr. Choo Yew Weng<br>`rc.mechanical@utp.edu.my` | Dr. Faizal Mustapha<br>`faizal.mustapha@utp.edu.my`<br>Dr. Ng Siew Ling<br>`ng.siewling@utp.edu.my` |
| 8 | Petroleum Engineering | FOE | Dr. Nurul Huda Zainal<br>`chair.petroleum@utp.edu.my` | Puan Norazlina Samad<br>`ae.petroleum@utp.edu.my` | Puan Rozita Mansor<br>`rc.petroleum@utp.edu.my` | Dr. Zulkifli Hamzah<br>`zulkifli.hamzah@utp.edu.my`<br>Dr. Anitha Pillai<br>`anitha.pillai@utp.edu.my` |
| 9 | Applied Sciences | FSMC | Dr. Lakshmi Devi Subramaniam<br>`chair.applied@utp.edu.my` | Puan Vimala Krishnan<br>`ae.applied@utp.edu.my` | Dr. Geetha Balasubramaniam<br>`rc.applied@utp.edu.my` | Dr. Rohana Ismail<br>`rohana.ismail@utp.edu.my`<br>Dr. Chua Boon Leng<br>`chua.boonleng@utp.edu.my` |
| 10 | Computing | FSMC | Dr. Lim Wei Chun<br>`chair@utp.edu.my` | Siti Academic Exec<br>`ae@utp.edu.my` | Research Centre Officer<br>`researchcentre@utp.edu.my` | Dr. Aisyah Rahman<br>`supervisor@utp.edu.my`<br>Prof. Dr. Kumaran Vellu<br>`kumaran.vellu@utp.edu.my` |
| 11 | Geosciences | FSMC | Dr. Mohd Fauzi Hassan<br>`chair.geosciences@utp.edu.my` | En Amirul Hakim<br>`ae.geosciences@utp.edu.my` | En Azmi Shah Rahman<br>`rc.geosciences@utp.edu.my` | Dr. Mohd Rizal Ahmad<br>`rizal.ahmad@utp.edu.my`<br>Dr. Yeap Siew Hoon<br>`yeap.siewhoon@utp.edu.my` |
| 12 | Management | FSMC | Dr. Wong Pei Shan<br>`chair.management@utp.edu.my` | Puan Lee Siew Mei<br>`ae.management@utp.edu.my` | Puan Yap Wan Ling<br>`rc.management@utp.edu.my` | Dr. Nor Hafizah Aziz<br>`hafizah.aziz@utp.edu.my`<br>Dr. Sanjay Menon<br>`sanjay.menon@utp.edu.my` |

## Students

### Roster students

The two accounts the README walkthroughs use.

| Email | Matric | Name | Programme | Department | Supervisor |
|---|---|---|---|---|---|
| `student@utp.edu.my` | 22001001 | Ahmad Danial | MSc Full-Time | Computing | Dr. Aisyah Rahman |
| `student2@utp.edu.my` | 22001002 | Nur Farah Adilah | PhD Part-Time | Computing | Dr. Aisyah Rahman |

### Cohort: 180 students (109 male, 71 female)

15 per department. Matric `2410DDNN`: `DD` is the department number from the table above, `NN` 01–15. Email `<name>_<matric>@utp.edu.my` (name lower-case, spaces and *bin/binti* dropped). Programmes rotate MSc Full-Time, PhD Full-Time, MSc Part-Time, PhD Part-Time. Each department's students alternate between its two supervisors. Gender is stored in `student_genders` (Workstation rooms are gendered). Each has an active Study Candidacy expiring 3–35 months out, so **the 96 Full-Time students can pick a workstation**; the 84 Part-Time ones get "not eligible", which is the rule (Full-Time research only), not a bug.

| # | Department | Male | Female | Total |
|---|---|---:|---:|---:|
| 1 | Foundation in Business Management & Computing | 9 | 6 | 15 |
| 2 | Foundation in Engineering & Science | 8 | 7 | 15 |
| 3 | Chemical Engineering | 10 | 5 | 15 |
| 4 | Civil & Environmental Engineering | 9 | 6 | 15 |
| 5 | Electrical & Electronics Engineering | 8 | 7 | 15 |
| 6 | Integrated Engineering | 9 | 6 | 15 |
| 7 | Mechanical Engineering | 10 | 5 | 15 |
| 8 | Petroleum Engineering | 9 | 6 | 15 |
| 9 | Applied Sciences | 8 | 7 | 15 |
| 10 | Computing | 10 | 5 | 15 |
| 11 | Geosciences | 9 | 6 | 15 |
| 12 | Management | 10 | 5 | 15 |
| | **Total** | **109** | **71** | **180** |

#### 1. Foundation in Business Management & Computing

| Matric | Name | Gender | Programme | Email | Supervisor |
|---|---|---|---|---|---|
| 24100101 | Ahmad Faris bin Ismail | Male | MSc Full-Time | `ahmadfarisismail_24100101@utp.edu.my` | Dr. Hasliza Omar |
| 24100102 | Tan Wei Jie | Male | PhD Full-Time | `tanweijie_24100102@utp.edu.my` | Mr. Daniel Lee Chee Wai |
| 24100103 | Arjun Subramaniam | Male | MSc Part-Time | `arjunsubramaniam_24100103@utp.edu.my` | Dr. Hasliza Omar |
| 24100104 | Muhammad Irfan bin Abdullah | Male | PhD Part-Time | `muhammadirfanabdullah_24100104@utp.edu.my` | Mr. Daniel Lee Chee Wai |
| 24100105 | Lim Jun Hao | Male | MSc Full-Time | `limjunhao_24100105@utp.edu.my` | Dr. Hasliza Omar |
| 24100106 | Karthik Krishnan | Male | PhD Full-Time | `karthikkrishnan_24100106@utp.edu.my` | Mr. Daniel Lee Chee Wai |
| 24100107 | Amirul Hakim bin Rahman | Male | MSc Part-Time | `amirulhakimrahman_24100107@utp.edu.my` | Dr. Hasliza Omar |
| 24100108 | Lee Zhi Hao | Male | PhD Part-Time | `leezhihao_24100108@utp.edu.my` | Mr. Daniel Lee Chee Wai |
| 24100109 | Vikram Muthu | Male | MSc Full-Time | `vikrammuthu_24100109@utp.edu.my` | Dr. Hasliza Omar |
| 24100110 | Nur Aisyah binti Ismail | Female | PhD Full-Time | `nuraisyahismail_24100110@utp.edu.my` | Mr. Daniel Lee Chee Wai |
| 24100111 | Tan Xin Yi | Female | MSc Part-Time | `tanxinyi_24100111@utp.edu.my` | Dr. Hasliza Omar |
| 24100112 | Priya Subramaniam | Female | PhD Part-Time | `priyasubramaniam_24100112@utp.edu.my` | Mr. Daniel Lee Chee Wai |
| 24100113 | Siti Hajar binti Abdullah | Female | MSc Full-Time | `sitihajarabdullah_24100113@utp.edu.my` | Dr. Hasliza Omar |
| 24100114 | Lim Hui Min | Female | PhD Full-Time | `limhuimin_24100114@utp.edu.my` | Mr. Daniel Lee Chee Wai |
| 24100115 | Kavitha Krishnan | Female | MSc Part-Time | `kavithakrishnan_24100115@utp.edu.my` | Dr. Hasliza Omar |

#### 2. Foundation in Engineering & Science

| Matric | Name | Gender | Programme | Email | Supervisor |
|---|---|---|---|---|---|
| 24100201 | Haziq Danial bin Hassan | Male | MSc Full-Time | `haziqdanialhassan_24100201@utp.edu.my` | Dr. Suhaimi Yahya |
| 24100202 | Wong Kai Xiang | Male | PhD Full-Time | `wongkaixiang_24100202@utp.edu.my` | Dr. Kalaivani Murugan |
| 24100203 | Pravin Rajendran | Male | MSc Part-Time | `pravinrajendran_24100203@utp.edu.my` | Dr. Suhaimi Yahya |
| 24100204 | Syafiq Aiman bin Yusof | Male | PhD Part-Time | `syafiqaimanyusof_24100204@utp.edu.my` | Dr. Kalaivani Murugan |
| 24100205 | Ng Jia Hao | Male | MSc Full-Time | `ngjiahao_24100205@utp.edu.my` | Dr. Suhaimi Yahya |
| 24100206 | Suresh Ganesan | Male | PhD Full-Time | `sureshganesan_24100206@utp.edu.my` | Dr. Kalaivani Murugan |
| 24100207 | Firdaus Hakimi bin Ibrahim | Male | MSc Part-Time | `firdaushakimiibrahim_24100207@utp.edu.my` | Dr. Suhaimi Yahya |
| 24100208 | Ong Wen Kai | Male | PhD Part-Time | `ongwenkai_24100208@utp.edu.my` | Dr. Kalaivani Murugan |
| 24100209 | Nurul Izzah binti Rahman | Female | MSc Full-Time | `nurulizzahrahman_24100209@utp.edu.my` | Dr. Suhaimi Yahya |
| 24100210 | Lee Mei Ling | Female | PhD Full-Time | `leemeiling_24100210@utp.edu.my` | Dr. Kalaivani Murugan |
| 24100211 | Divya Muthu | Female | MSc Part-Time | `divyamuthu_24100211@utp.edu.my` | Dr. Suhaimi Yahya |
| 24100212 | Farah Nadhirah binti Hassan | Female | PhD Part-Time | `farahnadhirahhassan_24100212@utp.edu.my` | Dr. Kalaivani Murugan |
| 24100213 | Wong Jia Wen | Female | MSc Full-Time | `wongjiawen_24100213@utp.edu.my` | Dr. Suhaimi Yahya |
| 24100214 | Shalini Rajendran | Female | PhD Full-Time | `shalinirajendran_24100214@utp.edu.my` | Dr. Kalaivani Murugan |
| 24100215 | Aina Sofea binti Yusof | Female | MSc Part-Time | `ainasofeayusof_24100215@utp.edu.my` | Dr. Suhaimi Yahya |

#### 3. Chemical Engineering

| Matric | Name | Gender | Programme | Email | Supervisor |
|---|---|---|---|---|---|
| 24100301 | Ganesh Pillai | Male | MSc Full-Time | `ganeshpillai_24100301@utp.edu.my` | Dr. Farid Kamal |
| 24100302 | Aiman Hafiz bin Osman | Male | PhD Full-Time | `aimanhafizosman_24100302@utp.edu.my` | Dr. Lau Kok Keong |
| 24100303 | Chong Hong Wei | Male | MSc Part-Time | `chonghongwei_24100303@utp.edu.my` | Dr. Farid Kamal |
| 24100304 | Ravi Nair | Male | PhD Part-Time | `ravinair_24100304@utp.edu.my` | Dr. Lau Kok Keong |
| 24100305 | Luqman Hakim bin Zainal | Male | MSc Full-Time | `luqmanhakimzainal_24100305@utp.edu.my` | Dr. Farid Kamal |
| 24100306 | Chan Chee Keong | Male | PhD Full-Time | `chancheekeong_24100306@utp.edu.my` | Dr. Lau Kok Keong |
| 24100307 | Dinesh Raman | Male | MSc Part-Time | `dineshraman_24100307@utp.edu.my` | Dr. Farid Kamal |
| 24100308 | Nabil Arif bin Kamarudin | Male | PhD Part-Time | `nabilarifkamarudin_24100308@utp.edu.my` | Dr. Lau Kok Keong |
| 24100309 | Goh Boon Kiat | Male | MSc Full-Time | `gohboonkiat_24100309@utp.edu.my` | Dr. Farid Kamal |
| 24100310 | Harish Selvam | Male | PhD Full-Time | `harishselvam_24100310@utp.edu.my` | Dr. Lau Kok Keong |
| 24100311 | Ng Pei Shan | Female | MSc Part-Time | `ngpeishan_24100311@utp.edu.my` | Dr. Farid Kamal |
| 24100312 | Revathi Ganesan | Female | PhD Part-Time | `revathiganesan_24100312@utp.edu.my` | Dr. Lau Kok Keong |
| 24100313 | Nur Insyirah binti Ibrahim | Female | MSc Full-Time | `nurinsyirahibrahim_24100313@utp.edu.my` | Dr. Farid Kamal |
| 24100314 | Ong Yi Ting | Female | PhD Full-Time | `ongyiting_24100314@utp.edu.my` | Dr. Lau Kok Keong |
| 24100315 | Anusha Pillai | Female | MSc Part-Time | `anushapillai_24100315@utp.edu.my` | Dr. Farid Kamal |

#### 4. Civil & Environmental Engineering

| Matric | Name | Gender | Programme | Email | Supervisor |
|---|---|---|---|---|---|
| 24100401 | Hafiy Iskandar bin Salleh | Male | MSc Full-Time | `hafiyiskandarsalleh_24100401@utp.edu.my` | Dr. Noor Azlina Hamid |
| 24100402 | Yap Yong Sheng | Male | PhD Full-Time | `yapyongsheng_24100402@utp.edu.my` | Dr. Teo Wee Liang |
| 24100403 | Naveen Kumar | Male | MSc Part-Time | `naveenkumar_24100403@utp.edu.my` | Dr. Noor Azlina Hamid |
| 24100404 | Zikri Asyraf bin Ismail | Male | PhD Part-Time | `zikriasyrafismail_24100404@utp.edu.my` | Dr. Teo Wee Liang |
| 24100405 | Teh Wei Jie | Male | MSc Full-Time | `tehweijie_24100405@utp.edu.my` | Dr. Noor Azlina Hamid |
| 24100406 | Arjun Krishnan | Male | PhD Full-Time | `arjunkrishnan_24100406@utp.edu.my` | Dr. Teo Wee Liang |
| 24100407 | Ikhwan Zarif bin Abdullah | Male | MSc Part-Time | `ikhwanzarifabdullah_24100407@utp.edu.my` | Dr. Noor Azlina Hamid |
| 24100408 | Loh Jun Hao | Male | PhD Part-Time | `lohjunhao_24100408@utp.edu.my` | Dr. Teo Wee Liang |
| 24100409 | Karthik Muthu | Male | MSc Full-Time | `karthikmuthu_24100409@utp.edu.my` | Dr. Noor Azlina Hamid |
| 24100410 | Alya Maisarah binti Osman | Female | PhD Full-Time | `alyamaisarahosman_24100410@utp.edu.my` | Dr. Teo Wee Liang |
| 24100411 | Chong Siew Mei | Female | MSc Part-Time | `chongsiewmei_24100411@utp.edu.my` | Dr. Noor Azlina Hamid |
| 24100412 | Deepa Nair | Female | PhD Part-Time | `deepanair_24100412@utp.edu.my` | Dr. Teo Wee Liang |
| 24100413 | Hani Batrisya binti Zainal | Female | MSc Full-Time | `hanibatrisyazainal_24100413@utp.edu.my` | Dr. Noor Azlina Hamid |
| 24100414 | Chan Li Ying | Female | PhD Full-Time | `chanliying_24100414@utp.edu.my` | Dr. Teo Wee Liang |
| 24100415 | Nisha Raman | Female | MSc Part-Time | `nisharaman_24100415@utp.edu.my` | Dr. Noor Azlina Hamid |

#### 5. Electrical & Electronics Engineering

| Matric | Name | Gender | Programme | Email | Supervisor |
|---|---|---|---|---|---|
| 24100501 | Adam Rayyan bin Rahman | Male | MSc Full-Time | `adamrayyanrahman_24100501@utp.edu.my` | Dr. Siti Nurhaliza |
| 24100502 | Yeoh Zhi Hao | Male | PhD Full-Time | `yeohzhihao_24100502@utp.edu.my` | Dr. Balachandran Ramasamy |
| 24100503 | Vikram Rajendran | Male | MSc Part-Time | `vikramrajendran_24100503@utp.edu.my` | Dr. Siti Nurhaliza |
| 24100504 | Danish Haikal bin Hassan | Male | PhD Part-Time | `danishhaikalhassan_24100504@utp.edu.my` | Dr. Balachandran Ramasamy |
| 24100505 | Chin Kai Xiang | Male | MSc Full-Time | `chinkaixiang_24100505@utp.edu.my` | Dr. Siti Nurhaliza |
| 24100506 | Pravin Ganesan | Male | PhD Full-Time | `pravinganesan_24100506@utp.edu.my` | Dr. Balachandran Ramasamy |
| 24100507 | Arif Fahmi bin Yusof | Male | MSc Part-Time | `ariffahmiyusof_24100507@utp.edu.my` | Dr. Siti Nurhaliza |
| 24100508 | Koh Jia Hao | Male | PhD Part-Time | `kohjiahao_24100508@utp.edu.my` | Dr. Balachandran Ramasamy |
| 24100509 | Syazwani Amira binti Kamarudin | Female | MSc Full-Time | `syazwaniamirakamarudin_24100509@utp.edu.my` | Dr. Siti Nurhaliza |
| 24100510 | Goh Shu Fen | Female | PhD Full-Time | `gohshufen_24100510@utp.edu.my` | Dr. Balachandran Ramasamy |
| 24100511 | Meena Selvam | Female | MSc Part-Time | `meenaselvam_24100511@utp.edu.my` | Dr. Siti Nurhaliza |
| 24100512 | Nadia Huda binti Salleh | Female | PhD Part-Time | `nadiahudasalleh_24100512@utp.edu.my` | Dr. Balachandran Ramasamy |
| 24100513 | Yap Wen Qi | Female | MSc Full-Time | `yapwenqi_24100513@utp.edu.my` | Dr. Siti Nurhaliza |
| 24100514 | Thivya Kumar | Female | PhD Full-Time | `thivyakumar_24100514@utp.edu.my` | Dr. Balachandran Ramasamy |
| 24100515 | Puteri Balqis binti Ismail | Female | MSc Part-Time | `puteribalqisismail_24100515@utp.edu.my` | Dr. Siti Nurhaliza |

#### 6. Integrated Engineering

| Matric | Name | Gender | Programme | Email | Supervisor |
|---|---|---|---|---|---|
| 24100601 | Suresh Pillai | Male | MSc Full-Time | `sureshpillai_24100601@utp.edu.my` | Dr. Khairil Anwar Rosli |
| 24100602 | Ahmad Faris bin Osman | Male | PhD Full-Time | `ahmadfarisosman_24100602@utp.edu.my` | Dr. Ooi Mei Fong |
| 24100603 | Tan Hong Wei | Male | MSc Part-Time | `tanhongwei_24100603@utp.edu.my` | Dr. Khairil Anwar Rosli |
| 24100604 | Ganesh Nair | Male | PhD Part-Time | `ganeshnair_24100604@utp.edu.my` | Dr. Ooi Mei Fong |
| 24100605 | Muhammad Irfan bin Zainal | Male | MSc Full-Time | `muhammadirfanzainal_24100605@utp.edu.my` | Dr. Khairil Anwar Rosli |
| 24100606 | Lim Chee Keong | Male | PhD Full-Time | `limcheekeong_24100606@utp.edu.my` | Dr. Ooi Mei Fong |
| 24100607 | Ravi Raman | Male | MSc Part-Time | `raviraman_24100607@utp.edu.my` | Dr. Khairil Anwar Rosli |
| 24100608 | Amirul Hakim bin Kamarudin | Male | PhD Part-Time | `amirulhakimkamarudin_24100608@utp.edu.my` | Dr. Ooi Mei Fong |
| 24100609 | Lee Boon Kiat | Male | MSc Full-Time | `leeboonkiat_24100609@utp.edu.my` | Dr. Khairil Anwar Rosli |
| 24100610 | Teh Xin Yi | Female | PhD Full-Time | `tehxinyi_24100610@utp.edu.my` | Dr. Ooi Mei Fong |
| 24100611 | Priya Krishnan | Female | MSc Part-Time | `priyakrishnan_24100611@utp.edu.my` | Dr. Khairil Anwar Rosli |
| 24100612 | Iman Safiyya binti Abdullah | Female | PhD Part-Time | `imansafiyyaabdullah_24100612@utp.edu.my` | Dr. Ooi Mei Fong |
| 24100613 | Loh Hui Min | Female | MSc Full-Time | `lohhuimin_24100613@utp.edu.my` | Dr. Khairil Anwar Rosli |
| 24100614 | Kavitha Muthu | Female | PhD Full-Time | `kavithamuthu_24100614@utp.edu.my` | Dr. Ooi Mei Fong |
| 24100615 | Athirah Husna binti Rahman | Female | MSc Part-Time | `athirahhusnarahman_24100615@utp.edu.my` | Dr. Khairil Anwar Rosli |

#### 7. Mechanical Engineering

| Matric | Name | Gender | Programme | Email | Supervisor |
|---|---|---|---|---|---|
| 24100701 | Dinesh Selvam | Male | MSc Full-Time | `dineshselvam_24100701@utp.edu.my` | Dr. Faizal Mustapha |
| 24100702 | Haziq Danial bin Salleh | Male | PhD Full-Time | `haziqdanialsalleh_24100702@utp.edu.my` | Dr. Ng Siew Ling |
| 24100703 | Wong Yong Sheng | Male | MSc Part-Time | `wongyongsheng_24100703@utp.edu.my` | Dr. Faizal Mustapha |
| 24100704 | Harish Kumar | Male | PhD Part-Time | `harishkumar_24100704@utp.edu.my` | Dr. Ng Siew Ling |
| 24100705 | Syafiq Aiman bin Ismail | Male | MSc Full-Time | `syafiqaimanismail_24100705@utp.edu.my` | Dr. Faizal Mustapha |
| 24100706 | Ng Wei Jie | Male | PhD Full-Time | `ngweijie_24100706@utp.edu.my` | Dr. Ng Siew Ling |
| 24100707 | Naveen Subramaniam | Male | MSc Part-Time | `naveensubramaniam_24100707@utp.edu.my` | Dr. Faizal Mustapha |
| 24100708 | Firdaus Hakimi bin Abdullah | Male | PhD Part-Time | `firdaushakimiabdullah_24100708@utp.edu.my` | Dr. Ng Siew Ling |
| 24100709 | Ong Jun Hao | Male | MSc Full-Time | `ongjunhao_24100709@utp.edu.my` | Dr. Faizal Mustapha |
| 24100710 | Arjun Muthu | Male | PhD Full-Time | `arjunmuthu_24100710@utp.edu.my` | Dr. Ng Siew Ling |
| 24100711 | Yeoh Mei Ling | Female | MSc Part-Time | `yeohmeiling_24100711@utp.edu.my` | Dr. Faizal Mustapha |
| 24100712 | Divya Rajendran | Female | PhD Part-Time | `divyarajendran_24100712@utp.edu.my` | Dr. Ng Siew Ling |
| 24100713 | Liyana Qistina binti Hassan | Female | MSc Full-Time | `liyanaqistinahassan_24100713@utp.edu.my` | Dr. Faizal Mustapha |
| 24100714 | Chin Jia Wen | Female | PhD Full-Time | `chinjiawen_24100714@utp.edu.my` | Dr. Ng Siew Ling |
| 24100715 | Shalini Ganesan | Female | MSc Part-Time | `shaliniganesan_24100715@utp.edu.my` | Dr. Faizal Mustapha |

#### 8. Petroleum Engineering

| Matric | Name | Gender | Programme | Email | Supervisor |
|---|---|---|---|---|---|
| 24100801 | Aiman Hafiz bin Rahman | Male | MSc Full-Time | `aimanhafizrahman_24100801@utp.edu.my` | Dr. Zulkifli Hamzah |
| 24100802 | Chong Zhi Hao | Male | PhD Full-Time | `chongzhihao_24100802@utp.edu.my` | Dr. Anitha Pillai |
| 24100803 | Karthik Rajendran | Male | MSc Part-Time | `karthikrajendran_24100803@utp.edu.my` | Dr. Zulkifli Hamzah |
| 24100804 | Luqman Hakim bin Hassan | Male | PhD Part-Time | `luqmanhakimhassan_24100804@utp.edu.my` | Dr. Anitha Pillai |
| 24100805 | Chan Kai Xiang | Male | MSc Full-Time | `chankaixiang_24100805@utp.edu.my` | Dr. Zulkifli Hamzah |
| 24100806 | Vikram Ganesan | Male | PhD Full-Time | `vikramganesan_24100806@utp.edu.my` | Dr. Anitha Pillai |
| 24100807 | Nabil Arif bin Yusof | Male | MSc Part-Time | `nabilarifyusof_24100807@utp.edu.my` | Dr. Zulkifli Hamzah |
| 24100808 | Goh Jia Hao | Male | PhD Part-Time | `gohjiahao_24100808@utp.edu.my` | Dr. Anitha Pillai |
| 24100809 | Pravin Pillai | Male | MSc Full-Time | `pravinpillai_24100809@utp.edu.my` | Dr. Zulkifli Hamzah |
| 24100810 | Damia Irdina binti Yusof | Female | PhD Full-Time | `damiairdinayusof_24100810@utp.edu.my` | Dr. Anitha Pillai |
| 24100811 | Koh Pei Shan | Female | MSc Part-Time | `kohpeishan_24100811@utp.edu.my` | Dr. Zulkifli Hamzah |
| 24100812 | Revathi Pillai | Female | PhD Part-Time | `revathipillai_24100812@utp.edu.my` | Dr. Anitha Pillai |
| 24100813 | Nur Aisyah binti Osman | Female | MSc Full-Time | `nuraisyahosman_24100813@utp.edu.my` | Dr. Zulkifli Hamzah |
| 24100814 | Tan Siew Mei | Female | PhD Full-Time | `tansiewmei_24100814@utp.edu.my` | Dr. Anitha Pillai |
| 24100815 | Anusha Nair | Female | MSc Part-Time | `anushanair_24100815@utp.edu.my` | Dr. Zulkifli Hamzah |

#### 9. Applied Sciences

| Matric | Name | Gender | Programme | Email | Supervisor |
|---|---|---|---|---|---|
| 24100901 | Hafiy Iskandar bin Ibrahim | Male | MSc Full-Time | `hafiyiskandaribrahim_24100901@utp.edu.my` | Dr. Rohana Ismail |
| 24100902 | Yap Wen Kai | Male | PhD Full-Time | `yapwenkai_24100902@utp.edu.my` | Dr. Chua Boon Leng |
| 24100903 | Suresh Nair | Male | MSc Part-Time | `sureshnair_24100903@utp.edu.my` | Dr. Rohana Ismail |
| 24100904 | Zikri Asyraf bin Osman | Male | PhD Part-Time | `zikriasyrafosman_24100904@utp.edu.my` | Dr. Chua Boon Leng |
| 24100905 | Teh Hong Wei | Male | MSc Full-Time | `tehhongwei_24100905@utp.edu.my` | Dr. Rohana Ismail |
| 24100906 | Ganesh Raman | Male | PhD Full-Time | `ganeshraman_24100906@utp.edu.my` | Dr. Chua Boon Leng |
| 24100907 | Ikhwan Zarif bin Zainal | Male | MSc Part-Time | `ikhwanzarifzainal_24100907@utp.edu.my` | Dr. Rohana Ismail |
| 24100908 | Loh Chee Keong | Male | PhD Part-Time | `lohcheekeong_24100908@utp.edu.my` | Dr. Chua Boon Leng |
| 24100909 | Siti Hajar binti Zainal | Female | MSc Full-Time | `sitihajarzainal_24100909@utp.edu.my` | Dr. Rohana Ismail |
| 24100910 | Lim Li Ying | Female | PhD Full-Time | `limliying_24100910@utp.edu.my` | Dr. Chua Boon Leng |
| 24100911 | Deepa Raman | Female | MSc Part-Time | `deeparaman_24100911@utp.edu.my` | Dr. Rohana Ismail |
| 24100912 | Nurul Izzah binti Kamarudin | Female | PhD Part-Time | `nurulizzahkamarudin_24100912@utp.edu.my` | Dr. Chua Boon Leng |
| 24100913 | Lee Shu Fen | Female | MSc Full-Time | `leeshufen_24100913@utp.edu.my` | Dr. Rohana Ismail |
| 24100914 | Nisha Selvam | Female | PhD Full-Time | `nishaselvam_24100914@utp.edu.my` | Dr. Chua Boon Leng |
| 24100915 | Farah Nadhirah binti Salleh | Female | MSc Part-Time | `farahnadhirahsalleh_24100915@utp.edu.my` | Dr. Rohana Ismail |

#### 10. Computing

| Matric | Name | Gender | Programme | Email | Supervisor |
|---|---|---|---|---|---|
| 24101001 | Ravi Selvam | Male | MSc Full-Time | `raviselvam_24101001@utp.edu.my` | Dr. Aisyah Rahman |
| 24101002 | Adam Rayyan bin Kamarudin | Male | PhD Full-Time | `adamrayyankamarudin_24101002@utp.edu.my` | Prof. Dr. Kumaran Vellu |
| 24101003 | Yeoh Boon Kiat | Male | MSc Part-Time | `yeohboonkiat_24101003@utp.edu.my` | Dr. Aisyah Rahman |
| 24101004 | Dinesh Kumar | Male | PhD Part-Time | `dineshkumar_24101004@utp.edu.my` | Prof. Dr. Kumaran Vellu |
| 24101005 | Danish Haikal bin Salleh | Male | MSc Full-Time | `danishhaikalsalleh_24101005@utp.edu.my` | Dr. Aisyah Rahman |
| 24101006 | Chin Yong Sheng | Male | PhD Full-Time | `chinyongsheng_24101006@utp.edu.my` | Prof. Dr. Kumaran Vellu |
| 24101007 | Harish Subramaniam | Male | MSc Part-Time | `harishsubramaniam_24101007@utp.edu.my` | Dr. Aisyah Rahman |
| 24101008 | Arif Fahmi bin Ismail | Male | PhD Part-Time | `ariffahmiismail_24101008@utp.edu.my` | Prof. Dr. Kumaran Vellu |
| 24101009 | Koh Wei Jie | Male | MSc Full-Time | `kohweijie_24101009@utp.edu.my` | Dr. Aisyah Rahman |
| 24101010 | Naveen Krishnan | Male | PhD Full-Time | `naveenkrishnan_24101010@utp.edu.my` | Prof. Dr. Kumaran Vellu |
| 24101011 | Wong Wen Qi | Female | MSc Part-Time | `wongwenqi_24101011@utp.edu.my` | Dr. Aisyah Rahman |
| 24101012 | Meena Kumar | Female | PhD Part-Time | `meenakumar_24101012@utp.edu.my` | Prof. Dr. Kumaran Vellu |
| 24101013 | Aina Sofea binti Ismail | Female | MSc Full-Time | `ainasofeaismail_24101013@utp.edu.my` | Dr. Aisyah Rahman |
| 24101014 | Ng Xin Yi | Female | PhD Full-Time | `ngxinyi_24101014@utp.edu.my` | Prof. Dr. Kumaran Vellu |
| 24101015 | Thivya Subramaniam | Female | MSc Part-Time | `thivyasubramaniam_24101015@utp.edu.my` | Dr. Aisyah Rahman |

#### 11. Geosciences

| Matric | Name | Gender | Programme | Email | Supervisor |
|---|---|---|---|---|---|
| 24101101 | Ahmad Faris bin Rahman | Male | MSc Full-Time | `ahmadfarisrahman_24101101@utp.edu.my` | Dr. Mohd Rizal Ahmad |
| 24101102 | Tan Zhi Hao | Male | PhD Full-Time | `tanzhihao_24101102@utp.edu.my` | Dr. Yeap Siew Hoon |
| 24101103 | Arjun Rajendran | Male | MSc Part-Time | `arjunrajendran_24101103@utp.edu.my` | Dr. Mohd Rizal Ahmad |
| 24101104 | Muhammad Irfan bin Hassan | Male | PhD Part-Time | `muhammadirfanhassan_24101104@utp.edu.my` | Dr. Yeap Siew Hoon |
| 24101105 | Lim Kai Xiang | Male | MSc Full-Time | `limkaixiang_24101105@utp.edu.my` | Dr. Mohd Rizal Ahmad |
| 24101106 | Karthik Ganesan | Male | PhD Full-Time | `karthikganesan_24101106@utp.edu.my` | Dr. Yeap Siew Hoon |
| 24101107 | Amirul Hakim bin Yusof | Male | MSc Part-Time | `amirulhakimyusof_24101107@utp.edu.my` | Dr. Mohd Rizal Ahmad |
| 24101108 | Lee Jia Hao | Male | PhD Part-Time | `leejiahao_24101108@utp.edu.my` | Dr. Yeap Siew Hoon |
| 24101109 | Vikram Pillai | Male | MSc Full-Time | `vikrampillai_24101109@utp.edu.my` | Dr. Mohd Rizal Ahmad |
| 24101110 | Nur Insyirah binti Abdullah | Female | PhD Full-Time | `nurinsyirahabdullah_24101110@utp.edu.my` | Dr. Yeap Siew Hoon |
| 24101111 | Ong Hui Min | Female | MSc Part-Time | `onghuimin_24101111@utp.edu.my` | Dr. Mohd Rizal Ahmad |
| 24101112 | Priya Muthu | Female | PhD Part-Time | `priyamuthu_24101112@utp.edu.my` | Dr. Yeap Siew Hoon |
| 24101113 | Alya Maisarah binti Rahman | Female | MSc Full-Time | `alyamaisarahrahman_24101113@utp.edu.my` | Dr. Mohd Rizal Ahmad |
| 24101114 | Chong Mei Ling | Female | PhD Full-Time | `chongmeiling_24101114@utp.edu.my` | Dr. Yeap Siew Hoon |
| 24101115 | Kavitha Rajendran | Female | MSc Part-Time | `kavitharajendran_24101115@utp.edu.my` | Dr. Mohd Rizal Ahmad |

#### 12. Management

| Matric | Name | Gender | Programme | Email | Supervisor |
|---|---|---|---|---|---|
| 24101201 | Haziq Danial bin Ibrahim | Male | MSc Full-Time | `haziqdanialibrahim_24101201@utp.edu.my` | Dr. Nor Hafizah Aziz |
| 24101202 | Wong Wen Kai | Male | PhD Full-Time | `wongwenkai_24101202@utp.edu.my` | Dr. Sanjay Menon |
| 24101203 | Pravin Nair | Male | MSc Part-Time | `pravinnair_24101203@utp.edu.my` | Dr. Nor Hafizah Aziz |
| 24101204 | Syafiq Aiman bin Osman | Male | PhD Part-Time | `syafiqaimanosman_24101204@utp.edu.my` | Dr. Sanjay Menon |
| 24101205 | Ng Hong Wei | Male | MSc Full-Time | `nghongwei_24101205@utp.edu.my` | Dr. Nor Hafizah Aziz |
| 24101206 | Suresh Raman | Male | PhD Full-Time | `sureshraman_24101206@utp.edu.my` | Dr. Sanjay Menon |
| 24101207 | Firdaus Hakimi bin Zainal | Male | MSc Part-Time | `firdaushakimizainal_24101207@utp.edu.my` | Dr. Nor Hafizah Aziz |
| 24101208 | Ong Chee Keong | Male | PhD Part-Time | `ongcheekeong_24101208@utp.edu.my` | Dr. Sanjay Menon |
| 24101209 | Ganesh Selvam | Male | MSc Full-Time | `ganeshselvam_24101209@utp.edu.my` | Dr. Nor Hafizah Aziz |
| 24101210 | Aiman Hafiz bin Kamarudin | Male | PhD Full-Time | `aimanhafizkamarudin_24101210@utp.edu.my` | Dr. Sanjay Menon |
| 24101211 | Hani Batrisya binti Hassan | Female | MSc Part-Time | `hanibatrisyahassan_24101211@utp.edu.my` | Dr. Nor Hafizah Aziz |
| 24101212 | Chan Jia Wen | Female | PhD Part-Time | `chanjiawen_24101212@utp.edu.my` | Dr. Sanjay Menon |
| 24101213 | Divya Ganesan | Female | MSc Full-Time | `divyaganesan_24101213@utp.edu.my` | Dr. Nor Hafizah Aziz |
| 24101214 | Syazwani Amira binti Yusof | Female | PhD Full-Time | `syazwaniamirayusof_24101214@utp.edu.my` | Dr. Sanjay Menon |
| 24101215 | Goh Pei Shan | Female | MSc Part-Time | `gohpeishan_24101215@utp.edu.my` | Dr. Nor Hafizah Aziz |

## Demo-only accounts (80, `./sync.sh --fresh` only)

Made by `demo:seed` and the module demo seeders (Nureen's, Hani's, Chloe's). They are fixtures for those modules' demos: don't rely on them for anything else, and don't expect them after a plain `./sync.sh`.

| Email | Role | Name | Matric | Department | Supervisor |
|---|---|---|---|---|---|
| `lee.jiahui@example.com` | Student | Lee Jia Hui | 21000106 | Mechanical Engineering | Assoc Prof Dr Zainab binti Harun |
| `aleesya.hisham@example.com` | Student | Nur Aleesya binti Hisham | 21000109 | Applied Sciences | Assoc Prof Dr Rohayu binti Idris |
| `kavitha.selvaraj@example.com` | Student | Kavitha a/p Selvaraj | 21000110 | Chemical Engineering | Prof Dr Tan Wei Jie |
| `khadijah.mazlan@example.com` | Student | Siti Khadijah binti Mazlan | 22000104 | Civil & Environmental Engineering | Dr Kalaivani a/p Muthu |
| `tan.yixuan@example.com` | Student | Tan Yi Xuan | 22000108 | Computing | Assoc Prof Dr Nurul Aina binti Aziz |
| `nur.aina.batrisyia@utp.edu.my` | Student | Nur Aina Batrisyia | 22001010 | Computing | Dr. Aisyah Rahman |
| `muhammad.haziq.irfan@utp.edu.my` | Student | Muhammad Haziq Irfan | 22001011 | Chemical Engineering | Dr. Farid Kamal |
| `tan.wei.jian@utp.edu.my` | Student | Tan Wei Jian | 22001012 | Electrical & Electronics Engineering | Dr. Siti Nurhaliza |
| `divya.sri.kumar@utp.edu.my` | Student | Divya Sri Kumar | 22001013 | Computing | Prof. Dr. Kumaran Vellu |
| `muhammad.amirul.aiman@utp.edu.my` | Student | Muhammad Amirul Aiman | 22001014 | Chemical Engineering | Dr. Aisyah Rahman |
| `lee.xin.yi@utp.edu.my` | Student | Lee Xin Yi | 22001015 | Electrical & Electronics Engineering | Dr. Farid Kamal |
| `nurul.iman.shafiqah@utp.edu.my` | Student | Nurul Iman Shafiqah | 22001016 | Computing | Dr. Siti Nurhaliza |
| `balamurugan.raj@utp.edu.my` | Student | Balamurugan Raj | 22001017 | Chemical Engineering | Prof. Dr. Kumaran Vellu |
| `siti.khadijah.yusof@utp.edu.my` | Student | Siti Khadijah Yusof | 22001018 | Electrical & Electronics Engineering | Dr. Aisyah Rahman |
| `ong.chee.kiat@utp.edu.my` | Student | Ong Chee Kiat | 22001019 | Computing | Dr. Farid Kamal |
| `aishah.humaira@utp.edu.my` | Student | Aishah Humaira | 22001020 | Chemical Engineering | Dr. Siti Nurhaliza |
| `ravindran.subramaniam@utp.edu.my` | Student | Ravindran Subramaniam | 22001021 | Electrical & Electronics Engineering | Prof. Dr. Kumaran Vellu |
| `farhana.izzati@utp.edu.my` | Student | Farhana Izzati | 22001022 | Computing | Dr. Aisyah Rahman |
| `chong.mei.xuan@utp.edu.my` | Student | Chong Mei Xuan | 22001023 | Chemical Engineering | Dr. Farid Kamal |
| `ahmad.zaki.hilmi@utp.edu.my` | Student | Ahmad Zaki Hilmi | 22001024 | Electrical & Electronics Engineering | Dr. Siti Nurhaliza |
| `priya.dharshini@utp.edu.my` | Student | Priya Dharshini | 22001025 | Computing | Prof. Dr. Kumaran Vellu |
| `ga-extension-demo@utp.edu.my` | Student | Nurul Izzah | 22090001 | Computing | Dr. Aisyah Rahman |
| `supervision-main-demo@utp.edu.my` | Student | Amirul Hakim | 22090002 | Computing | Dr. Aisyah Rahman |
| `supervision-co-demo@utp.edu.my` | Student | Balqis Aina | 22090003 | Computing | Dr. Aisyah Rahman |
| `ga-cert-demo@utp.edu.my` | Student | Chong Wei Xuan | 22090004 | Computing | Dr. Aisyah Rahman |
| `gra-cert-demo@utp.edu.my` | Student | Divya Shanmugam | 22090005 | Computing | Dr. Aisyah Rahman |
| `charter-22091001@utp.edu.my` | Student | Charter Demo 01 | 22091001 | Computing | Dr. Aisyah Rahman |
| `charter-22091002@utp.edu.my` | Student | Charter Demo 02 | 22091002 | Computing | Dr. Aisyah Rahman |
| `charter-22091003@utp.edu.my` | Student | Charter Demo 03 | 22091003 | Computing | Dr. Aisyah Rahman |
| `charter-22091004@utp.edu.my` | Student | Charter Demo 04 | 22091004 | Computing | Dr. Aisyah Rahman |
| `charter-22091005@utp.edu.my` | Student | Charter Demo 05 | 22091005 | Computing | Dr. Aisyah Rahman |
| `charter-22091006@utp.edu.my` | Student | Charter Demo 06 | 22091006 | Computing | Dr. Aisyah Rahman |
| `charter-22091007@utp.edu.my` | Student | Charter Demo 07 | 22091007 | Computing | Dr. Aisyah Rahman |
| `charter-22091011@utp.edu.my` | Student | Charter Demo 11 | 22091011 | Computing | Dr. Aisyah Rahman |
| `charter-22091012@utp.edu.my` | Student | Charter Demo 12 | 22091012 | Computing | Dr. Aisyah Rahman |
| `charter-22091013@utp.edu.my` | Student | Charter Demo 13 | 22091013 | Computing | Dr. Aisyah Rahman |
| `charter-22091021@utp.edu.my` | Student | Charter Demo 21 | 22091021 | Computing | Dr. Aisyah Rahman |
| `charter-22091022@utp.edu.my` | Student | Charter Demo 22 | 22091022 | Computing | Dr. Aisyah Rahman |
| `charter-22091023@utp.edu.my` | Student | Charter Demo 23 | 22091023 | Computing | Dr. Aisyah Rahman |
| `charter-22091024@utp.edu.my` | Student | Charter Demo 24 | 22091024 | Computing | Dr. Aisyah Rahman |
| `nurin.azlan@example.com` | Student | Nurin Batrisyia binti Azlan | 23000102 | Petroleum Engineering | Assoc Prof Dr Mohd Hairul bin Hashim |
| `harith.z@example.com` | Student | Harith Iskandar bin Zulkifli | 23000103 | Chemical Engineering | Prof Dr Tan Wei Jie |
| `amirah.kamal@example.com` | Student | Amirah Sofea binti Kamal | 23000105 | Management | Dr Faridah binti Osman |
| `danish.faisal@example.com` | Student | Muhammad Danish bin Faisal | 23000107 | Electrical & Electronics Engineering | Dr Eric Yap Chee Keong |
| `aiman.roslan@example.com` | Student | Aiman Hakim bin Roslan | 24000101 | Computing | Assoc Prof Dr Nurul Aina binti Aziz |
| `chloe_26007318@utp.edu.my` | Student | Chloe Ching | 26007318 | – | – |
| `history.h01@example.com` | Student | Adam Haikal bin Rosli | H2500001 | Computing | Assoc Prof Dr Nurul Aina binti Aziz |
| `history.h02@example.com` | Student | Balqis Humaira binti Azmi | H2500002 | Petroleum Engineering | Assoc Prof Dr Mohd Hairul bin Hashim |
| `history.h03@example.com` | Student | Chua Wei Ling | H2500003 | Chemical Engineering | Prof Dr Tan Wei Jie |
| `history.h04@example.com` | Student | Darshan a/l Kumar | H2500004 | Civil & Environmental Engineering | Dr Kalaivani a/p Muthu |
| `history.h05@example.com` | Student | Elya Maisarah binti Hamdan | H2500005 | Management | Dr Faridah binti Osman |
| `history.h06@example.com` | Student | Farhan Iqbal bin Zakaria | H2500006 | Mechanical Engineering | Assoc Prof Dr Zainab binti Harun |
| `history.h07@example.com` | Student | Goh Shu Fen | H2500007 | Electrical & Electronics Engineering | Dr Eric Yap Chee Keong |
| `history.h08@example.com` | Student | Hafiy Danial bin Rahim | H2500008 | Applied Sciences | Assoc Prof Dr Rohayu binti Idris |
| `history.h09@example.com` | Student | Irdina Safiya binti Mokhtar | H2500009 | Computing | Assoc Prof Dr Nurul Aina binti Aziz |
| `history.h10@example.com` | Student | Jeevan a/l Ramasamy | H2500010 | Petroleum Engineering | Assoc Prof Dr Mohd Hairul bin Hashim |
| `history.h11@example.com` | Student | Khairul Anwar bin Salleh | H2500011 | Chemical Engineering | Prof Dr Tan Wei Jie |
| `history.h12@example.com` | Student | Lim Jia Wen | H2500012 | Civil & Environmental Engineering | Dr Kalaivani a/p Muthu |
| `history.h13@example.com` | Student | Marissa Qistina binti Yusof | H2500013 | Management | Dr Faridah binti Osman |
| `history.h14@example.com` | Student | Naufal Hakimi bin Azlan | H2500014 | Mechanical Engineering | Assoc Prof Dr Zainab binti Harun |
| `history.h15@example.com` | Student | Ong Kai Xiang | H2500015 | Electrical & Electronics Engineering | Dr Eric Yap Chee Keong |
| `history.h16@example.com` | Student | Puteri Alya binti Kamal | H2500016 | Applied Sciences | Assoc Prof Dr Rohayu binti Idris |
| `history.h17@example.com` | Student | Qayyum Rizqi bin Hassan | H2500017 | Computing | Assoc Prof Dr Nurul Aina binti Aziz |
| `history.h18@example.com` | Student | Rachel Tan Hui Min | H2500018 | Petroleum Engineering | Assoc Prof Dr Mohd Hairul bin Hashim |
| `history.h19@example.com` | Student | Syazwan Amri bin Daud | H2500019 | Chemical Engineering | Prof Dr Tan Wei Jie |
| `history.h20@example.com` | Student | Thivya a/p Selvam | H2500020 | Civil & Environmental Engineering | Dr Kalaivani a/p Muthu |
| `history.h21@example.com` | Student | Umairah Husna binti Ismail | H2500021 | Management | Dr Faridah binti Osman |
| `history.h22@example.com` | Student | Vernon Lee Chee Keong | H2500022 | Mechanical Engineering | Assoc Prof Dr Zainab binti Harun |
| `history.h23@example.com` | Student | Wardah Insyirah binti Osman | H2500023 | Electrical & Electronics Engineering | Dr Eric Yap Chee Keong |
| `history.h24@example.com` | Student | Yusri Fikri bin Jamil | H2500024 | Applied Sciences | Assoc Prof Dr Rohayu binti Idris |
| `zulhilmi.rahman@utp.edu.my` | Academic Executive | Dr. Zulhilmi Rahman | – | Electrical & Electronics Engineering | – |
| `co-supervisor-demo@utp.edu.my` | Supervisor | Dr. Faizal Hakimi | – | Computing | – |
| `eric.yap@example.com` | Supervisor | Dr Eric Yap Chee Keong | – | Electrical & Electronics Engineering | – |
| `faridah.osman@example.com` | Supervisor | Dr Faridah binti Osman | – | Management | – |
| `hairul.hashim@example.com` | Supervisor | Assoc Prof Dr Mohd Hairul bin Hashim | – | Petroleum Engineering | – |
| `kalaivani.muthu@example.com` | Supervisor | Dr Kalaivani a/p Muthu | – | Civil & Environmental Engineering | – |
| `nurul.aziz@example.com` | Supervisor | Assoc Prof Dr Nurul Aina binti Aziz | – | Computing | – |
| `rohayu.idris@example.com` | Supervisor | Assoc Prof Dr Rohayu binti Idris | – | Applied Sciences | – |
| `tan.weijie@example.com` | Supervisor | Prof Dr Tan Wei Jie | – | Chemical Engineering | – |
| `zainab.harun@example.com` | Supervisor | Assoc Prof Dr Zainab binti Harun | – | Mechanical Engineering | – |
