<?php

namespace App\Modules\Jason\Database\Seeders;

use App\Modules\Jason\Models\AppointmentExaminer;
use App\Modules\Jason\Models\PoolExaminer;
use Illuminate\Database\Seeder;

/**
 * The examiner list a Chair nominates from: 50 internal, 50 external.
 *
 * Fictional people, but shaped like the real thing -- UTP's actual
 * departments on the internal side, and on the external side the mix a CGS
 * list really carries: the Malaysian public universities first, then the
 * private and branch campuses, then regional and international institutions
 * for theses where the expertise is not in the country.
 *
 * Matched on email, so running it twice adds nothing and running it on a
 * list that already has people leaves them -- and their appointment history
 * -- untouched.
 *
 *     php artisan db:seed --class="App\Modules\Jason\Database\Seeders\ExaminerPoolSeeder"
 *
 * Not called from Core's DatabaseSeeder: that file belongs to the whole team,
 * and 100 examiners are only useful to this module.
 */
class ExaminerPoolSeeder extends Seeder
{
    /** UTP's departments, as they are printed on an appointment letter. */
    protected const DEPARTMENTS = [
        'CIS' => 'Department of Computer & Information Sciences',
        'EEE' => 'Department of Electrical & Electronic Engineering',
        'ME' => 'Department of Mechanical Engineering',
        'CHE' => 'Department of Chemical Engineering',
        'CVE' => 'Department of Civil & Environmental Engineering',
        'PE' => 'Department of Petroleum Engineering',
        'FAS' => 'Department of Fundamental & Applied Sciences',
        'MH' => 'Department of Management & Humanities',
        'ENG' => 'Faculty of Engineering',
    ];

    /** Institution name and the address block below it, per external campus. */
    protected const INSTITUTIONS = [
        'UPNM' => ['Universiti Pertahanan Nasional Malaysia', "57000 Kuala Lumpur\nMalaysia"],
        'UM' => ['Universiti Malaya', "50603 Kuala Lumpur\nMalaysia"],
        'USM' => ['Universiti Sains Malaysia', "14300 Nibong Tebal, Pulau Pinang\nMalaysia"],
        'UTM' => ['Universiti Teknologi Malaysia', "81310 Johor Bahru, Johor\nMalaysia"],
        'UKM' => ['Universiti Kebangsaan Malaysia', "43600 Bangi, Selangor\nMalaysia"],
        'UPM' => ['Universiti Putra Malaysia', "43400 Serdang, Selangor\nMalaysia"],
        'UNIMAS' => ['Universiti Malaysia Sarawak', "94300 Kota Samarahan, Sarawak\nMalaysia"],
        'UITM' => ['Universiti Teknologi MARA', "40450 Shah Alam, Selangor\nMalaysia"],
        'IIUM' => ['International Islamic University Malaysia', "53100 Kuala Lumpur\nMalaysia"],
        'UMPSA' => ['Universiti Malaysia Pahang Al-Sultan Abdullah', "26600 Pekan, Pahang\nMalaysia"],
        'UMS' => ['Universiti Malaysia Sabah', "88400 Kota Kinabalu, Sabah\nMalaysia"],
        'UTEM' => ['Universiti Teknikal Malaysia Melaka', "76100 Durian Tunggal, Melaka\nMalaysia"],
        'UNIMAP' => ['Universiti Malaysia Perlis', "02600 Arau, Perlis\nMalaysia"],
        'UMT' => ['Universiti Malaysia Terengganu', "21030 Kuala Nerus, Terengganu\nMalaysia"],
        'USIM' => ['Universiti Sains Islam Malaysia', "71800 Nilai, Negeri Sembilan\nMalaysia"],
        'UPSI' => ['Universiti Pendidikan Sultan Idris', "35900 Tanjong Malim, Perak\nMalaysia"],
        'MMU' => ['Multimedia University', "63100 Cyberjaya, Selangor\nMalaysia"],
        'TAYLORS' => ["Taylor's University", "47500 Subang Jaya, Selangor\nMalaysia"],
        'SUNWAY' => ['Sunway University', "47500 Bandar Sunway, Selangor\nMalaysia"],
        'UCSI' => ['UCSI University', "56000 Kuala Lumpur\nMalaysia"],
        'CURTINM' => ['Curtin University Malaysia', "98009 Miri, Sarawak\nMalaysia"],
        'HWUM' => ['Heriot-Watt University Malaysia', "62200 Putrajaya\nMalaysia"],
        'SWINBURNE' => ['Swinburne University of Technology Sarawak', "93350 Kuching, Sarawak\nMalaysia"],
        'NUS' => ['National University of Singapore', "1 Engineering Drive 2\nSingapore 117576"],
        'NTU' => ['Nanyang Technological University', "50 Nanyang Avenue\nSingapore 639798"],
        'CHULA' => ['Chulalongkorn University', "Bangkok 10330\nThailand"],
        'ITB' => ['Institut Teknologi Bandung', "Bandung 40132\nIndonesia"],
        'KFUPM' => ['King Fahd University of Petroleum & Minerals', "Dhahran 31261\nSaudi Arabia"],
        'KHALIFA' => ['Khalifa University', "Abu Dhabi\nUnited Arab Emirates"],
        'LEEDS' => ['University of Leeds', "Leeds LS2 9JT\nUnited Kingdom"],
        'IMPERIAL' => ['Imperial College London', "London SW7 2AZ\nUnited Kingdom"],
        'DELFT' => ['Delft University of Technology', "2628 CD Delft\nThe Netherlands"],
        'UQ' => ['The University of Queensland', "Brisbane QLD 4072\nAustralia"],
        'CURTIN' => ['Curtin University', "Perth WA 6845\nAustralia"],
        'UNSW' => ['UNSW Sydney', "Sydney NSW 2052\nAustralia"],
        'MONASH' => ['Monash University', "Clayton VIC 3800\nAustralia"],
        'ISCT' => ['Institute of Science Tokyo', "Tokyo 152-8550\nJapan"],
        'KAIST' => ['Korea Advanced Institute of Science & Technology', "Daejeon 34141\nSouth Korea"],
        'HKUST' => ['The Hong Kong University of Science & Technology', "Clear Water Bay, Kowloon\nHong Kong"],
        'TUM' => ['Technical University of Munich', "80333 Munich\nGermany"],
    ];

    /**
     * [name, department key, expertise, email]
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    protected const INTERNAL = [
        ['Ir Dr Idris Bin Othman', 'ENG', 'Structural engineering', 'idris.othman@utp.edu.my'],
        ['Assoc Prof Dr Nurul Ain Binti Mahmud', 'CIS', 'Machine learning and data analytics', 'nurulain.mahmud@utp.edu.my'],
        ['Dr Muhammad Faris Bin Zulkifli', 'EEE', 'Power systems and smart grids', 'faris.zulkifli@utp.edu.my'],
        ['Prof Dr Siti Hajar Binti Ramli', 'CHE', 'Carbon capture and process intensification', 'sitihajar.ramli@utp.edu.my'],
        ['Ir Dr Lim Wei Jian', 'ME', 'Computational fluid dynamics', 'weijian.lim@utp.edu.my'],
        ['Dr Aisyah Binti Kamaruddin', 'PE', 'Reservoir engineering and enhanced oil recovery', 'aisyah.kamaruddin@utp.edu.my'],
        ['Assoc Prof Dr Rajendran A/L Subramaniam', 'FAS', 'Applied mathematics and numerical methods', 'rajendran.s@utp.edu.my'],
        ['Dr Hafizah Binti Mohd Yusof', 'CIS', 'Software engineering and information systems', 'hafizah.yusof@utp.edu.my'],
        ['Ts Dr Ahmad Syafiq Bin Hassan', 'CVE', 'Geotechnical engineering', 'syafiq.hassan@utp.edu.my'],
        ['Prof Dr Tan Mei Ling', 'MH', 'Technology management and innovation', 'meiling.tan@utp.edu.my'],
        ['Prof Dr Zulkifli Bin Hamzah', 'CHE', 'Catalysis and reaction engineering', 'zulkifli.hamzah@utp.edu.my'],
        ['Assoc Prof Dr Lee Chin Hoe', 'CIS', 'Computer vision and image processing', 'chinhoe.lee@utp.edu.my'],
        ['Dr Nur Syazwani Binti Rahim', 'CIS', 'Human-computer interaction', 'syazwani.rahim@utp.edu.my'],
        ['Ir Dr Mohd Ridzuan Bin Salleh', 'CVE', 'Structural health monitoring', 'ridzuan.salleh@utp.edu.my'],
        ['Dr Priya A/P Balakrishnan', 'FAS', 'Statistical modelling', 'priya.balakrishnan@utp.edu.my'],
        ['Assoc Prof Dr Tengku Amir Bin Tengku Ismail', 'PE', 'Drilling engineering', 'amir.tengku@utp.edu.my'],
        ['Prof Dr Hasnah Binti Mohd Zaid', 'FAS', 'Nanofluids and applied physics', 'hasnah.zaid@utp.edu.my'],
        ['Dr Goh Kok Leong', 'ME', 'Robotics and mechatronics', 'kokleong.goh@utp.edu.my'],
        ['Ts Dr Fatimah Binti Zaharah', 'CIS', 'Cloud computing and virtualisation', 'fatimah.zaharah@utp.edu.my'],
        ['Ir Dr Azman Bin Che Mat', 'EEE', 'Control systems and automation', 'azman.chemat@utp.edu.my'],
        ['Dr Vinod A/L Ramachandran', 'EEE', 'Embedded systems design', 'vinod.rama@utp.edu.my'],
        ['Assoc Prof Dr Sharifah Nurul Binti Syed Omar', 'MH', 'Organisational behaviour', 'sharifah.nurul@utp.edu.my'],
        ['Prof Ir Dr Kamarudin Bin Abdul Ghani', 'ME', 'Thermofluids and heat transfer', 'kamarudin.ghani@utp.edu.my'],
        ['Dr Chan Yoke Peng', 'CHE', 'Membrane separation technology', 'yokepeng.chan@utp.edu.my'],
        ['Dr Muhd Danial Bin Roslan', 'CVE', 'Transportation engineering', 'danial.roslan@utp.edu.my'],
        ['Assoc Prof Dr Noor Hidayah Binti Kamal', 'CIS', 'Information security and cryptography', 'hidayah.kamal@utp.edu.my'],
        ['Prof Dr Sivakumar A/L Muniandy', 'FAS', 'Computational chemistry', 'sivakumar.muniandy@utp.edu.my'],
        ['Dr Wan Aisyah Binti Wan Daud', 'PE', 'Petrophysics and formation evaluation', 'wanaisyah.daud@utp.edu.my'],
        ['Ts Dr Ong Boon Keat', 'ME', 'Additive manufacturing', 'boonkeat.ong@utp.edu.my'],
        ['Ir Dr Roslinda Binti Hashim', 'EEE', 'Power electronics and drives', 'roslinda.hashim@utp.edu.my'],
        ['Dr Amir Hamzah Bin Jaafar', 'CHE', 'Process control and optimisation', 'amirhamzah.jaafar@utp.edu.my'],
        ['Assoc Prof Dr Kavitha A/P Suppiah', 'MH', 'Knowledge management', 'kavitha.suppiah@utp.edu.my'],
        ['Prof Dr Yusof Bin Maarof', 'ENG', 'Offshore structures', 'yusof.maarof@utp.edu.my'],
        ['Dr Lim Sze Yin', 'CIS', 'Data mining and knowledge discovery', 'szeyin.lim@utp.edu.my'],
        ['Dr Hairul Nizam Bin Ibrahim', 'CVE', 'Water resources engineering', 'hairulnizam.ibrahim@utp.edu.my'],
        ['Ts Dr Nadia Binti Mahmood', 'FAS', 'Materials characterisation', 'nadia.mahmood@utp.edu.my'],
        ['Assoc Prof Dr Teoh Ming Fatt', 'ME', 'Vibration and structural dynamics', 'mingfatt.teoh@utp.edu.my'],
        ['Ir Dr Shahrul Nizam Bin Yaakub', 'EEE', 'Renewable energy integration', 'shahrul.yaakub@utp.edu.my'],
        ['Dr Anisah Binti Kamarul Zaman', 'CHE', 'Bioprocess engineering', 'anisah.zaman@utp.edu.my'],
        ['Prof Dr Ramesh A/L Kumaran', 'PE', 'Enhanced oil recovery modelling', 'ramesh.kumaran@utp.edu.my'],
        ['Dr Farhana Binti Md Nor', 'CIS', 'Natural language processing', 'farhana.mdnor@utp.edu.my'],
        ['Assoc Prof Dr Chew Wai Loon', 'FAS', 'Photonics and optical materials', 'wailoon.chew@utp.edu.my'],
        ['Ts Dr Izzat Bin Zainuddin', 'CVE', 'Construction informatics and BIM', 'izzat.zainuddin@utp.edu.my'],
        ['Dr Suraya Binti Abdul Rashid', 'MH', 'Sustainability and corporate governance', 'suraya.rashid@utp.edu.my'],
        ['Ir Dr Norhayati Binti Salleh', 'ME', 'Manufacturing systems engineering', 'norhayati.salleh@utp.edu.my'],
        ['Prof Dr Arif Bin Mohd Noor', 'EEE', 'Wireless sensor networks', 'arif.mohdnoor@utp.edu.my'],
        ['Dr Tan Hui Min', 'CHE', 'Carbon capture materials', 'huimin.tan@utp.edu.my'],
        ['Assoc Prof Dr Zulhelmi Bin Arifin', 'PE', 'Reservoir simulation', 'zulhelmi.arifin@utp.edu.my'],
        ['Dr Saraswathy A/P Devan', 'FAS', 'Applied statistics and reliability', 'saraswathy.devan@utp.edu.my'],
        ['Ts Dr Faizal Bin Mokhtar', 'CIS', 'Internet of Things and edge computing', 'faizal.mokhtar@utp.edu.my'],
    ];

    /**
     * [name, institution key, faculty or school, expertise, email]
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    protected const EXTERNAL = [
        ['Professor Ir Dr Mohamed Alias Yusof', 'UPNM', 'Department of Civil Engineering', 'Reinforced concrete structures', 'alias@upnm.edu.my'],
        ['Prof Dr Noraini Binti Abdullah', 'UM', 'Faculty of Computer Science & Information Technology', 'Artificial intelligence and natural language processing', 'noraini.abdullah@um.edu.my'],
        ['Assoc Prof Dr Chong Kok Wai', 'USM', 'School of Electrical & Electronic Engineering', 'Wireless communications and signal processing', 'kwchong@usm.edu.my'],
        ['Prof Ir Dr Zainal Abidin Bin Mohd Yusof', 'UTM', 'School of Chemical & Energy Engineering', 'Chemical process safety', 'zainal.abidin@utm.my'],
        ['Dr Farah Hanim Binti Ismail', 'UKM', 'Faculty of Engineering & Built Environment', 'Renewable energy systems', 'farahhanim@ukm.edu.my'],
        ['Prof Dr Ravi Shankar A/L Krishnan', 'UPM', 'Faculty of Engineering', 'Materials science and nanocomposites', 'ravishankar@upm.edu.my'],
        ['Assoc Prof Dr Wong Siew Ling', 'UNIMAS', 'Faculty of Computer Science & Information Technology', 'Database systems and big data', 'slwong@unimas.my'],
        ['Prof Dr Amirul Hakim Bin Rosli', 'UITM', 'School of Civil Engineering', 'Construction management', 'amirulhakim@uitm.edu.my'],
        ['Professor David Chen', 'NUS', 'Department of Civil & Environmental Engineering', 'Petroleum geoscience', 'david.chen@nus.edu.sg'],
        ['Dr Sarah Mitchell', 'MONASH', 'Faculty of Information Technology', 'Cybersecurity and network systems', 'sarah.mitchell@monash.edu'],
        ['Prof Dr Rosli Bin Mahmud', 'UTM', 'Faculty of Computing', 'Software architecture', 'rosli.mahmud@utm.my'],
        ['Assoc Prof Dr Lau Chee Kwan', 'UM', 'Faculty of Engineering', 'Structural dynamics', 'cklau@um.edu.my'],
        ['Dr Nurhidayah Binti Shafie', 'USM', 'School of Computer Sciences', 'Machine learning for health informatics', 'nurhidayah@usm.edu.my'],
        ['Prof Ir Dr Ganesan A/L Marimuthu', 'UPM', 'Faculty of Engineering', 'Highway and pavement engineering', 'ganesan@upm.edu.my'],
        ['Assoc Prof Dr Siti Mariam Binti Jalil', 'UKM', 'Faculty of Science & Technology', 'Applied mathematics', 'sitimariam@ukm.edu.my'],
        ['Dr Yeoh Kim Seng', 'UITM', 'School of Electrical Engineering', 'Antenna and microwave design', 'kimseng@uitm.edu.my'],
        ['Prof Dr Norazlin Binti Hamid', 'IIUM', 'Kulliyyah of Engineering', 'Biomedical instrumentation', 'norazlin@iium.edu.my'],
        ['Assoc Prof Dr Hisham Bin Mat Daud', 'UMPSA', 'Faculty of Chemical & Process Engineering Technology', 'Reaction kinetics', 'hisham@umpsa.edu.my'],
        ['Dr Julia Anak Sagan', 'UNIMAS', 'Faculty of Engineering', 'Environmental engineering', 'jsagan@unimas.my'],
        ['Prof Dr Chin Fook Yuen', 'UMS', 'Faculty of Engineering', 'Coastal and offshore engineering', 'fychin@ums.edu.my'],
        ['Ts Dr Zarina Binti Abu Bakar', 'UTEM', 'Faculty of Electronics & Computer Technology', 'VLSI design', 'zarina@utem.edu.my'],
        ['Assoc Prof Dr Ridwan Bin Hamzah', 'UNIMAP', 'Faculty of Mechanical Engineering Technology', 'Composite materials', 'ridwan@unimap.edu.my'],
        ['Dr Nor Azlina Binti Yaacob', 'UMT', 'Faculty of Ocean Engineering Technology', 'Marine hydrodynamics', 'norazlina@umt.edu.my'],
        ['Prof Dr Khairul Anuar Bin Sulaiman', 'USIM', 'Faculty of Science & Technology', 'Cryptographic protocols', 'khairul@usim.edu.my'],
        ['Assoc Prof Dr Meera A/P Nagarajan', 'UPSI', 'Faculty of Science & Mathematics', 'Computational modelling in education technology', 'meera@upsi.edu.my'],
        ['Prof Dr Loh Chun Keat', 'MMU', 'Faculty of Engineering', 'Intelligent control systems', 'ckloh@mmu.edu.my'],
        ['Dr Vanessa Tan Li Wen', 'TAYLORS', 'School of Engineering', 'Sustainable building design', 'vanessa.tan@taylors.edu.my'],
        ['Assoc Prof Dr Arun A/L Devaraj', 'SUNWAY', 'School of Engineering & Technology', 'Energy storage systems', 'arund@sunway.edu.my'],
        ['Prof Dr Shamsul Bin Kamaruzaman', 'UCSI', 'Faculty of Engineering, Technology & Built Environment', 'Process safety and risk assessment', 'shamsul@ucsiuniversity.edu.my'],
        ['Dr Emily Ngu Siew Ping', 'CURTINM', 'Faculty of Engineering & Science', 'Wastewater treatment', 'emily.ngu@curtin.edu.my'],
        ['Assoc Prof Dr Iain McAllister', 'HWUM', 'School of Energy, Geoscience, Infrastructure & Society', 'Petroleum geomechanics', 'i.mcallister@hw.ac.uk'],
        ['Dr Lucy Anak Jugah', 'SWINBURNE', 'Faculty of Engineering, Computing & Science', 'Image analytics', 'ljugah@swinburne.edu.my'],
        ['Prof Wong Kah Hoe', 'NTU', 'School of Mechanical & Aerospace Engineering', 'Aerodynamics', 'khwong@ntu.edu.sg'],
        ['Assoc Prof Dr Somchai Pattanakarn', 'CHULA', 'Faculty of Engineering', 'Geotechnical modelling', 'somchai.p@chula.ac.th'],
        ['Prof Dr Bambang Wijaya', 'ITB', 'Faculty of Mining & Petroleum Engineering', 'Reservoir characterisation', 'bambang.w@itb.ac.id'],
        ['Prof Dr Abdulaziz Al-Harbi', 'KFUPM', 'College of Petroleum Engineering & Geosciences', 'Well completion and stimulation', 'aharbi@kfupm.edu.sa'],
        ['Assoc Prof Dr Fatima Al-Mansoori', 'KHALIFA', 'Department of Chemical Engineering', 'Natural gas processing', 'fatima.mansoori@ku.ac.ae'],
        ['Prof Richard Holloway', 'LEEDS', 'School of Civil Engineering', 'Concrete durability', 'r.holloway@leeds.ac.uk'],
        ['Dr Priyanka Sharma', 'IMPERIAL', 'Department of Computing', 'Distributed systems', 'p.sharma@imperial.ac.uk'],
        ['Prof Dr Jeroen van Dijk', 'DELFT', 'Faculty of Civil Engineering & Geosciences', 'Offshore geotechnics', 'j.vandijk@tudelft.nl'],
        ['Assoc Prof Dr Michael O\'Brien', 'UQ', 'School of Chemical Engineering', 'Multiphase flow', 'm.obrien@uq.edu.au'],
        ['Prof Dr Helen Zhang', 'CURTIN', 'School of Electrical Engineering, Computing & Mathematical Sciences', 'Signal processing', 'h.zhang@curtin.edu.au'],
        ['Prof Dr Kenji Nakamura', 'ISCT', 'School of Materials & Chemical Technology', 'Functional materials', 'nakamura.k@isct.ac.jp'],
        ['Assoc Prof Dr Ji-Hoon Park', 'KAIST', 'Department of Mechanical Engineering', 'Precision manufacturing', 'jhpark@kaist.ac.kr'],
        ['Prof Dr Cheung Man Wai', 'HKUST', 'Department of Civil & Environmental Engineering', 'Smart infrastructure', 'mwcheung@ust.hk'],
        ['Assoc Prof Dr Sophie Laurent', 'UNSW', 'School of Minerals & Energy Resources Engineering', 'Carbon dioxide storage', 's.laurent@unsw.edu.au'],
        ['Prof Dr Klaus Berger', 'TUM', 'Department of Electrical Engineering', 'High-voltage engineering', 'k.berger@tum.de'],
        ['Dr Rohana Binti Awang', 'UPNM', 'Faculty of Engineering', 'Blast and impact engineering', 'rohana@upnm.edu.my'],
        ['Assoc Prof Dr Teh Boon Hock', 'MONASH', 'School of Information Technology', 'Software testing and verification', 'boonhock.teh@monash.edu'],
        ['Prof Dr Hazel Lim Mei Yee', 'NUS', 'Department of Industrial Systems Engineering', 'Operations research', 'hazel.lim@nus.edu.sg'],
    ];

    public function run(): void
    {
        $added = 0;

        foreach (self::INTERNAL as [$name, $key, $expertise, $email]) {
            $department = self::DEPARTMENTS[$key];

            $added += $this->add($email, [
                'name' => $name,
                'examiner_type' => AppointmentExaminer::TYPE_INTERNAL,
                'institution' => $department.', UTP',
                'address' => $department."\nUniversiti Teknologi PETRONAS\n32610 Seri Iskandar, Perak",
                'expertise' => $expertise,
            ]);
        }

        foreach (self::EXTERNAL as [$name, $key, $faculty, $expertise, $email]) {
            [$institution, $address] = self::INSTITUTIONS[$key];

            $added += $this->add($email, [
                'name' => $name,
                'examiner_type' => AppointmentExaminer::TYPE_EXTERNAL,
                'institution' => $institution,
                'address' => $faculty."\n".$institution."\n".$address,
                'expertise' => $expertise,
            ]);
        }

        $total = PoolExaminer::count();

        $this->command?->info("Examiner list: {$added} added, {$total} on the list "
            .'('.PoolExaminer::where('examiner_type', AppointmentExaminer::TYPE_INTERNAL)->count().' internal, '
            .PoolExaminer::where('examiner_type', AppointmentExaminer::TYPE_EXTERNAL)->count().' external).');
    }

    /** Returns 1 if this examiner was new, 0 if they were already listed. */
    protected function add(string $email, array $attributes): int
    {
        return PoolExaminer::firstOrCreate(['email' => $email], $attributes + ['is_active' => true])
            ->wasRecentlyCreated ? 1 : 0;
    }
}
