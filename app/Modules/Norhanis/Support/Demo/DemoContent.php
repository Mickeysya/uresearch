<?php

namespace App\Modules\Norhanis\Support\Demo;

/**
 * Fictional content for the Travel / Publication / Claims demo data
 * (norhanis:demo-seed): paper titles, journals and conferences by field,
 * co-authors, reviewers and contact people. No real people: every external
 * address is on example.com / example.edu, reviewers carry a ".demo" UTP
 * address, and phone numbers sit in a 012-000 range.
 */
final class DemoContent
{
    /** Department => [topics, journals, conferences]. */
    public const FIELDS = [
        'Computing' => [
            ['Graph Neural Networks for Anomaly Detection in Industrial IoT Streams', 'Federated Learning for Privacy-Preserving Medical Image Classification', 'Explainable Transformers for Malay Sentiment Analysis', 'Edge-Cloud Task Offloading with Deep Reinforcement Learning', 'Lightweight Intrusion Detection for Smart Campus Networks', 'Knowledge Graph Completion for Oil and Gas Maintenance Logs'],
            ['Journal of Intelligent Computing Systems', 'International Journal of Data Science and Analytics', 'Applied Soft Computing Letters'],
            ['International Conference on Computer and Information Sciences', 'Asia-Pacific Symposium on Machine Learning Applications'],
        ],
        'Chemical Engineering' => [
            ['Ionic Liquid Screening for Post-Combustion CO2 Capture', 'Kinetic Modelling of Palm Oil Mill Effluent Biogas Upgrading', 'Membrane Fouling Mitigation in Produced Water Treatment', 'Process Intensification of Biodiesel Production in Microreactors', 'Life-Cycle Assessment of Green Hydrogen from Biomass Gasification'],
            ['Journal of Process Systems Engineering', 'Chemical Engineering Research Letters', 'Separation and Purification Advances'],
            ['International Conference on Process Engineering and Advanced Materials', 'Regional Symposium on Chemical Engineering'],
        ],
        'Electrical & Electronics Engineering' => [
            ['Partial Discharge Classification in Medium-Voltage Cables Using CNNs', 'Grid-Forming Inverter Control for Weak Distribution Networks', 'Energy Harvesting Antennas for Battery-Free Sensors', 'Fault Location in Solar PV Arrays with Wavelet Features', 'Low-Power Analog Front End for Wearable ECG Monitoring'],
            ['Journal of Power and Energy Systems', 'Electronics and Signal Processing Letters', 'IET-Style Journal of Smart Grid Research'],
            ['International Conference on Intelligent and Advanced Systems', 'Asia Power Electronics Forum'],
        ],
        'Civil & Environmental Engineering' => [
            ['Self-Healing Concrete with Bacterial Spores for Coastal Structures', 'Flood Risk Mapping of Perak River Basin Using Hydrodynamic Models', 'Recycled Aggregate Performance in Rigid Pavements', 'Constructed Wetlands for Landfill Leachate Polishing', 'Seismic Fragility of Low-Rise Reinforced Concrete Buildings'],
            ['Journal of Sustainable Civil Infrastructure', 'Environmental Engineering and Management Letters', 'Construction Materials Review'],
            ['International Conference on Civil, Offshore and Environmental Engineering', 'Asian Symposium on Water Resources'],
        ],
        'Mechanical Engineering' => [
            ['Heat Transfer Enhancement with Hybrid Nanofluids in Microchannels', 'Fatigue Life Prediction of Additively Manufactured Titanium Brackets', 'Vibration-Based Condition Monitoring of Gas Compressors', 'Thermal Management of Lithium-Ion Packs with Phase-Change Materials', 'Erosion-Corrosion of Duplex Steel in Sand-Laden Flow'],
            ['Journal of Mechanical Engineering Science', 'Thermal Science and Engineering Progress Letters', 'International Journal of Manufacturing Technology'],
            ['International Conference on Mechanical, Manufacturing and Plant Engineering', 'Asia Pacific Conference on Thermal Engineering'],
        ],
        'Petroleum Engineering' => [
            ['Low-Salinity Water Flooding in Carbonate Reservoirs: A Core Study', 'Machine Learning Rate-of-Penetration Prediction for Offshore Wells', 'Foam-Assisted CO2 EOR in Heterogeneous Sandstones', 'Wellbore Stability Modelling in Depleted Shale Formations', 'Asphaltene Deposition Risk in Deepwater Flowlines'],
            ['Journal of Petroleum Exploration and Production Technology', 'Upstream Energy Research Letters', 'Journal of Natural Gas Science and Engineering Studies'],
            ['Offshore Technology Conference Asia', 'International Petroleum Technology Forum'],
        ],
        'Applied Sciences' => [
            ['Green Synthesis of Silver Nanoparticles from Mangrove Leaf Extract', 'Photocatalytic Degradation of Dyes on Doped TiO2 Films', 'Fractional-Order Models of Tumour Growth', 'Microplastic Distribution in Peninsular Malaysia Estuaries', 'Quantum Dot Sensitised Solar Cells with Natural Dyes'],
            ['Journal of Applied Physics and Chemistry', 'Malaysian Journal of Fundamental and Applied Sciences Review', 'Materials Science Letters'],
            ['International Conference on Fundamental and Applied Sciences', 'Asian Symposium on Materials Chemistry'],
        ],
        'Management' => [
            ['Digital Transformation Readiness of Malaysian Oil and Gas SMEs', 'Green Human Resource Practices and Employee Retention', 'ESG Disclosure and Firm Value in Bursa Malaysia', 'Project Governance in Public-Private Infrastructure Partnerships', 'Technology Acceptance of E-Wallets among Rural Consumers'],
            ['Journal of Management and Sustainability', 'Asian Journal of Business Research', 'International Journal of Project Management Studies'],
            ['International Conference on Business, Management and Technology', 'Asia Management Research Symposium'],
        ],
        'Geosciences' => [
            ['Sequence Stratigraphy of the Malay Basin Miocene Successions', 'Seismic Attribute Analysis of Carbonate Build-Ups Offshore Sarawak', 'Landslide Susceptibility Mapping in the Cameron Highlands', 'Geochemical Fingerprinting of Crude Oils from the Penyu Basin', 'Rock Physics Templates for Gas Sand Discrimination'],
            ['Journal of Asian Earth Sciences Letters', 'Bulletin of Applied Geoscience', 'Marine and Petroleum Geology Review'],
            ['Southeast Asia Petroleum Exploration Conference', 'International Geoscience Symposium'],
        ],
        'Integrated Engineering' => [
            ['Digital Twin Framework for Offshore Platform Integrity', 'Multi-Criteria Decision Model for Decommissioning Options', 'Systems Engineering Approach to Modular Plant Design', 'Risk-Based Inspection Optimisation with Bayesian Networks', 'Sustainable Product Design Metrics for Engineering Education'],
            ['Journal of Integrated Engineering Systems', 'Engineering Systems and Design Letters', 'Journal of Asset Integrity Management'],
            ['International Conference on Integrated Engineering', 'Asia Systems Engineering Forum'],
        ],
        'Foundation in Business Management & Computing' => [
            ['Gamified Learning Analytics in Pre-University Programming Courses', 'Predicting Foundation Student Performance with Ensemble Models', 'Blended Learning and Self-Regulation among Foundation Students', 'Chatbot Support for First-Year Business Students', 'Digital Literacy Gaps in Foundation Business Cohorts'],
            ['Journal of Educational Technology and Computing', 'Asian Journal of University Education Studies', 'Journal of Business Education Research'],
            ['International Conference on Education and Learning Technologies', 'Asia Pacific Forum on Foundation Studies'],
        ],
        'Foundation in Engineering & Science' => [
            ['Inquiry-Based Laboratories for Foundation Physics', 'Misconceptions in Pre-University Calculus: A Diagnostic Study', 'Low-Cost Sensor Kits for Foundation Chemistry Practicals', 'Peer-Assisted Learning in Foundation Engineering Mathematics', 'Virtual Labs for Teaching Electrical Circuits'],
            ['Journal of STEM Education Research', 'Asian Journal of Engineering Education', 'Science Education Letters'],
            ['International Conference on Engineering Education', 'Asian Symposium on Science Teaching'],
        ],
    ];

    public const PUBLISHERS = ['Elsevier', 'Springer Nature', 'Taylor & Francis', 'Wiley', 'MDPI', 'IEEE', 'IOP Publishing', 'Penerbit UTP Press'];

    public const ORGANISERS = ['Universiti Teknologi PETRONAS', 'Institution of Engineers Malaysia', 'Asia Research Society', 'Society of Petroleum Engineers (Malaysia Section)', 'Malaysian Society for Engineering Education'];

    public const LOCAL_VENUES = ['Kuala Lumpur Convention Centre, Kuala Lumpur', 'Universiti Teknologi PETRONAS, Seri Iskandar, Perak', 'Penang Waterfront Convention Centre, Penang', 'Borneo Convention Centre, Kuching, Sarawak', 'Putrajaya International Convention Centre, Putrajaya'];

    public const INTERNATIONAL_VENUES = ['Marina Bay Sands Expo, Singapore', 'Bangkok International Trade & Exhibition Centre, Thailand', 'Tokyo Big Sight, Japan', 'Jakarta Convention Center, Indonesia', 'Melbourne Convention and Exhibition Centre, Australia', 'ExCeL London, United Kingdom'];

    public const TRAVEL_LOCAL = ['Universiti Malaya, Kuala Lumpur', 'PETRONAS Research Sdn Bhd, Bangi, Selangor', 'Universiti Sains Malaysia, Penang', 'Universiti Malaysia Sarawak, Kota Samarahan', 'Port Dickson Field Station, Negeri Sembilan', 'Kerteh Integrated Complex, Terengganu', 'Universiti Teknologi Malaysia, Johor Bahru'];

    public const TRAVEL_INTERNATIONAL = ['National University of Singapore, Singapore', 'Imperial College London, United Kingdom', 'Kyoto University, Japan', 'Chulalongkorn University, Bangkok, Thailand', 'University of Western Australia, Perth'];

    /** Type of request => reason for travelling. */
    public const TRAVEL_REASONS = [
        'research_attachment' => 'Research attachment with the partner laboratory',
        'training_seminar_talk' => 'Attend a short course on advanced simulation methods',
        'field_visit' => 'Field visit to collect site observations',
        'meeting' => 'Progress meeting with the industry co-supervisor',
        'data_collection' => 'Collect samples and measurement data',
        'joint_supervision' => 'Joint supervision session with the external co-supervisor',
    ];

    public const OTHER_REQUESTS = ['Equipment calibration at the partner facility', 'Industry consultation workshop', 'Thesis-writing retreat organised by the research centre'];

    public const CO_AUTHORS = [
        ['Dr. Arif Hakimi', 'Senior Lecturer', 'Universiti Demo Malaysia'],
        ['Prof. Helena Marsh', 'Professor', 'Northbridge Institute of Technology'],
        ['Dr. Kenji Watanabe', 'Associate Professor', 'Eastport University'],
        ['Ir. Siti Hajar Ramli', 'Principal Engineer', 'Demo Energy Sdn Bhd'],
        ['Dr. Priya Raman', 'Research Fellow', 'Lakeside University'],
        ['Mr. Daniel Okafor', 'PhD Candidate', 'Riverton University'],
        ['Dr. Lim Pei Shan', 'Lecturer', 'Universiti Contoh Selatan'],
        ['Prof. Marco Bellini', 'Professor', 'Politecnico Demo'],
    ];

    /** UTP reviewers, fictional: the ".demo" address keeps them apart from real staff. */
    public const REVIEWERS = [
        'Dr. Nadia Karim', 'Dr. Hafiz Rosdi', 'Assoc. Prof. Dr. Wong Mei Yee', 'Dr. Suresh Pillai', 'Dr. Aisyah Mokhtar',
        'Prof. Dr. Rahman Idris', 'Dr. Tan Kok Wai', 'Dr. Farhana Zulkifli', 'Assoc. Prof. Dr. Lee Chin Hock', 'Dr. Azman Shah',
        'Dr. Kavitha Subramaniam', 'Dr. Iskandar Yusof',
    ];

    public const CONTACTS = ['Ms. Aina Rashid', 'Mr. Jason Teo', 'Dr. Mohd Faiz', 'Ms. Grace Liew', 'Mr. Hairi Jamal'];

    public const BANKS = ['Maybank', 'CIMB Bank', 'Bank Islam', 'RHB Bank', 'Public Bank', 'Hong Leong Bank', 'AmBank', 'Bank Rakyat'];

    public const REJECTION_REMARKS = [
        'supervisor' => 'The supporting documents do not match the stated purpose. Please refile with the correct letter.',
        'chair' => 'Not aligned with the department research priorities this semester.',
        'cgs_review' => 'The invoice is not in the applicant\'s name. Please obtain a corrected invoice and apply again.',
        'senior_director' => 'Funding for this category has been fully committed for the current cycle.',
        'manager' => 'Proof of payment does not show the transaction reference.',
        'dean' => 'International travel for this purpose is not supported this cycle.',
    ];

    public static function field(?string $department): array
    {
        return self::FIELDS[$department] ?? self::FIELDS['Applied Sciences'];
    }

    public static function slug(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '.', strtolower(preg_replace('/^(Dr|Prof|Assoc|Ir|Mr|Ms)\.?\s+/i', '', str_replace(['Assoc. Prof. Dr. ', 'Prof. Dr. '], '', $name)))), '.');
    }

    public static function reviewerEmail(string $name): string
    {
        return self::slug($name).'.demo@utp.edu.my';
    }

    public static function externalEmail(string $name): string
    {
        return self::slug($name).'@example.edu';
    }

    public static function contactEmail(string $name): string
    {
        return self::slug($name).'@example.com';
    }
}
