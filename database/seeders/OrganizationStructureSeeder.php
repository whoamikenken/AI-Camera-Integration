<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Device;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class OrganizationStructureSeeder extends Seeder
{
    /**
     * Run the database seeds for sample organization, sites/locations, departments, and designations.
     */
    public function run(): void
    {
        // 1. Ensure Default Organization exists
        $org = Organization::firstOrCreate(
            ['code' => 'PINNACLE-HQ'],
            [
                'name' => 'Pinnacle Technologies Inc.',
                'address' => 'Corporate Tower, 26th Street, Bonifacio Global City, Taguig, Metro Manila, Philippines',
                'timezone' => 'Asia/Manila',
                'is_active' => true,
            ]
        );

        // 2. Sites & Locations
        $locations = [
            [
                'code' => 'HQ-BGC',
                'name' => 'Headquarters - BGC Taguig',
                'address' => 'Corporate Tower, 26th Street, Bonifacio Global City, Taguig City, Metro Manila',
                'timezone' => 'Asia/Manila',
                'coordinates' => '14.5484, 121.0509',
                'is_active' => true,
            ],
            [
                'code' => 'MAK-HUB',
                'name' => 'Makati Operations Center',
                'address' => 'Ayala Avenue, Makati Central Business District, Makati City, Metro Manila',
                'timezone' => 'Asia/Manila',
                'coordinates' => '14.5547, 121.0244',
                'is_active' => true,
            ],
            [
                'code' => 'ORT-DEV',
                'name' => 'Ortigas Development Facility',
                'address' => 'ADB Avenue, Ortigas Center, Pasig City, Metro Manila',
                'timezone' => 'Asia/Manila',
                'coordinates' => '14.5869, 121.0614',
                'is_active' => true,
            ],
            [
                'code' => 'CEB-SITE',
                'name' => 'Cebu Regional Tech Hub',
                'address' => 'Cebu IT Park, Barangay Apas, Cebu City, Cebu',
                'timezone' => 'Asia/Manila',
                'coordinates' => '10.3297, 123.9059',
                'is_active' => true,
            ],
            [
                'code' => 'CLK-LOG',
                'name' => 'Clark Logistics & Data Facility',
                'address' => 'Clark Freeport Zone, Angeles City, Pampanga',
                'timezone' => 'Asia/Manila',
                'coordinates' => '15.1764, 120.5284',
                'is_active' => true,
            ],
        ];

        $locationModels = [];
        foreach ($locations as $locData) {
            $locationModels[$locData['code']] = Location::updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'name' => $locData['name'],
                ],
                array_merge($locData, ['organization_id' => $org->id])
            );
        }

        // Attach existing unassigned devices to the primary HQ location
        if (isset($locationModels['HQ-BGC'])) {
            Device::whereNull('organization_id')
                ->orWhereNull('location_id')
                ->update([
                    'organization_id' => $org->id,
                    'location_id' => $locationModels['HQ-BGC']->id,
                ]);
        }

        // 3. Departments Hierarchy (Parent & Sub-Departments)
        $parentDepartments = [
            [
                'code' => 'EXEC',
                'name' => 'Executive & Leadership',
                'description' => 'Executive Board and Chief Officers overseeing overall corporate strategy and governance.',
                'is_active' => true,
            ],
            [
                'code' => 'IT-ENG',
                'name' => 'Information Technology & Engineering',
                'description' => 'Core software engineering, platform architecture, vision AI research, and cloud infrastructure.',
                'is_active' => true,
            ],
            [
                'code' => 'HR',
                'name' => 'Human Resources & People Ops',
                'description' => 'Talent acquisition, employee welfare, compensation & benefits, and organizational development.',
                'is_active' => true,
            ],
            [
                'code' => 'FIN',
                'name' => 'Finance & Accounting',
                'description' => 'Financial planning, corporate accounting, treasury, payroll processing, and audit compliance.',
                'is_active' => true,
            ],
            [
                'code' => 'OPS-SEC',
                'name' => 'Security & Facilities Operations',
                'description' => 'Physical perimeter security, biometric access control, surveillance monitoring, and workplace safety.',
                'is_active' => true,
            ],
            [
                'code' => 'SALES-MKT',
                'name' => 'Sales & Business Development',
                'description' => 'Client relationship management, enterprise accounts, marketing campaigns, and brand partnerships.',
                'is_active' => true,
            ],
        ];

        $deptModels = [];
        foreach ($parentDepartments as $deptData) {
            $deptModels[$deptData['code']] = Department::updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'name' => $deptData['name'],
                ],
                array_merge($deptData, ['organization_id' => $org->id, 'parent_id' => null])
            );
        }

        // Sub-departments under parent departments
        $subDepartments = [
            // Under IT & Engineering
            [
                'parent_code' => 'IT-ENG',
                'code' => 'SWE',
                'name' => 'Software Engineering',
                'description' => 'Full-stack application development, REST API engineering, and frontend UI/UX implementation.',
            ],
            [
                'parent_code' => 'IT-ENG',
                'code' => 'DEVOPS',
                'name' => 'DevOps & Cloud Infrastructure',
                'description' => 'CI/CD automation, container orchestration, MQTT brokers, Redis clusters, and database reliability.',
            ],
            [
                'parent_code' => 'IT-ENG',
                'code' => 'AI-VISION',
                'name' => 'Vision AI & Edge Analytics',
                'description' => 'Edge camera telemetry processing, face recognition algorithms, and real-time vision pipelines.',
            ],
            // Under Human Resources
            [
                'parent_code' => 'HR',
                'code' => 'HR-TA',
                'name' => 'Talent Acquisition',
                'description' => 'Recruitment, technical candidate screening, executive search, and onboarding programs.',
            ],
            [
                'parent_code' => 'HR',
                'code' => 'HR-OPS',
                'name' => 'People Operations & Relations',
                'description' => 'Employee attendance policy governance, leaves administration, and performance reviews.',
            ],
            // Under Security & Facilities
            [
                'parent_code' => 'OPS-SEC',
                'code' => 'SEC-ACCESS',
                'name' => 'Physical Access Control & Surveillance',
                'description' => 'Real-time turnstile monitoring, biometric face terminals, perimeter alerts, and visitor verification.',
            ],
            [
                'parent_code' => 'OPS-SEC',
                'code' => 'FACILITIES',
                'name' => 'Facilities & Building Maintenance',
                'description' => 'Premises management, power backup systems, HVAC, and workplace asset maintenance.',
            ],
        ];

        foreach ($subDepartments as $subData) {
            $parentId = isset($deptModels[$subData['parent_code']]) ? $deptModels[$subData['parent_code']]->id : null;
            $deptModels[$subData['code']] = Department::updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'name' => $subData['name'],
                ],
                [
                    'organization_id' => $org->id,
                    'code' => $subData['code'],
                    'name' => $subData['name'],
                    'parent_id' => $parentId,
                    'description' => $subData['description'],
                    'is_active' => true,
                ]
            );
        }

        // 4. Job Titles & Designations (Structured by Organizational Hierarchy Levels 1-6)
        $designations = [
            // Level 6: Executive / C-Suite
            [
                'code' => 'CEO',
                'name' => 'Chief Executive Officer',
                'level' => 6,
                'description' => 'Chief Executive responsible for corporate vision, overall management, and strategic growth.',
            ],
            [
                'code' => 'CTO',
                'name' => 'Chief Technology Officer',
                'level' => 6,
                'description' => 'Executive leader overseeing technology roadmap, AI engineering, and technical infrastructure.',
            ],
            [
                'code' => 'COO',
                'name' => 'Chief Operating Officer',
                'level' => 6,
                'description' => 'Executive leader overseeing enterprise operations, facilities, and physical security execution.',
            ],
            [
                'code' => 'CFO',
                'name' => 'Chief Financial Officer',
                'level' => 6,
                'description' => 'Executive leader managing financial compliance, risk management, and capital allocation.',
            ],

            // Level 5: Directors & Heads of Department
            [
                'code' => 'DIR-ENG',
                'name' => 'Director of Engineering',
                'level' => 5,
                'description' => 'Leads software engineering teams, system architectures, and technical quality standards.',
            ],
            [
                'code' => 'DIR-HR',
                'name' => 'Director of Human Resources',
                'level' => 5,
                'description' => 'Directs talent development, compensation structures, workplace policies, and labor relations.',
            ],
            [
                'code' => 'DIR-SEC',
                'name' => 'Director of Security & Safety',
                'level' => 5,
                'description' => 'Leads physical access control, CCTV surveillance policies, and occupational safety compliance.',
            ],

            // Level 4: Managers & Principal Leads
            [
                'code' => 'ENG-MGR',
                'name' => 'Engineering Manager',
                'level' => 4,
                'description' => 'Manages engineering sprint velocity, technical mentorship, and product delivery roadmaps.',
            ],
            [
                'code' => 'DEVOPS-LEAD',
                'name' => 'DevOps & Cloud Lead',
                'level' => 4,
                'description' => 'Architects cloud telemetry pipelines, MQTT broker reliability, and automated CI/CD deployments.',
            ],
            [
                'code' => 'HR-MGR',
                'name' => 'HR Operations Manager',
                'level' => 4,
                'description' => 'Oversees employee shift scheduling, attendance regularization workflows, and leaves approvals.',
            ],
            [
                'code' => 'SOC-MGR',
                'name' => 'Security Operations Center (SOC) Manager',
                'level' => 4,
                'description' => 'Directs daily physical security monitoring, blacklist alarms handling, and emergency protocols.',
            ],
            [
                'code' => 'FIN-MGR',
                'name' => 'Finance & Accounting Manager',
                'level' => 4,
                'description' => 'Manages accounts payable/receivable, payroll sign-offs, and financial audits.',
            ],

            // Level 3: Senior Specialists & Team Leads
            [
                'code' => 'SR-SWE',
                'name' => 'Senior Full-Stack Engineer',
                'level' => 3,
                'description' => 'Designs and builds backend services, WebSocket streaming, and frontend user interfaces.',
            ],
            [
                'code' => 'SR-DEVOPS-ENG',
                'name' => 'Senior DevOps Engineer',
                'level' => 3,
                'description' => 'Manages Linux servers, Redis queues, Mosquitto brokers, and PostgreSQL database optimizations.',
            ],
            [
                'code' => 'SR-AI-SPEC',
                'name' => 'Senior Vision AI Specialist',
                'level' => 3,
                'description' => 'Specializes in face recognition models, biometric template extraction, and camera hardware protocols.',
            ],
            [
                'code' => 'SR-HR-SPEC',
                'name' => 'Senior HR Specialist',
                'level' => 3,
                'description' => 'Coordinates employee benefits, dispute resolution, and attendance analytics reporting.',
            ],
            [
                'code' => 'SR-SEC-LEAD',
                'name' => 'Senior Security Shift Lead',
                'level' => 3,
                'description' => 'Leads on-duty security guard shifts, stranger inspection routines, and incident dispatch.',
            ],

            // Level 2: Mid-Level Professionals & Specialists
            [
                'code' => 'SWE',
                'name' => 'Software Engineer',
                'level' => 2,
                'description' => 'Develops web application features, Vue components, and RESTful API endpoints.',
            ],
            [
                'code' => 'DEVOPS-ENG',
                'name' => 'DevOps Engineer',
                'level' => 2,
                'description' => 'Maintains server monitoring, tunnel connections, and deployment automation scripts.',
            ],
            [
                'code' => 'HR-OFFICER',
                'name' => 'HR & Payroll Officer',
                'level' => 2,
                'description' => 'Processes employee attendance timesheets, biometric punch validations, and payroll exports.',
            ],
            [
                'code' => 'ACCOUNTANT',
                'name' => 'Corporate Accountant',
                'level' => 2,
                'description' => 'Maintains general ledgers, tax compliance filings, and departmental expense vouchers.',
            ],
            [
                'code' => 'ACCESS-OPR',
                'name' => 'Biometric Access Control Operator',
                'level' => 2,
                'description' => 'Operates the Camera Hub console, registers personnel biometric face photos, and issues visitor badges.',
            ],

            // Level 1: Entry Level & Support Staff
            [
                'code' => 'JR-SWE',
                'name' => 'Junior Software Engineer',
                'level' => 1,
                'description' => 'Assists in code reviews, bug fixes, and unit test automation.',
            ],
            [
                'code' => 'IT-TECH',
                'name' => 'IT Support Technician',
                'level' => 1,
                'description' => 'Provides on-site hardware troubleshooting, network cabling, and workstation setups.',
            ],
            [
                'code' => 'SEC-OFFICER',
                'name' => 'Security Guard / Access Officer',
                'level' => 1,
                'description' => 'Manned gate access control, physical badge verification, and visitor escorts.',
            ],
            [
                'code' => 'ADMIN-ASST',
                'name' => 'Administrative Assistant',
                'level' => 1,
                'description' => 'Provides office administrative assistance, visitor reception, and documentation support.',
            ],
        ];

        foreach ($designations as $desigData) {
            Designation::updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'name' => $desigData['name'],
                ],
                array_merge($desigData, [
                    'organization_id' => $org->id,
                    'is_active' => true,
                ])
            );
        }
    }
}
