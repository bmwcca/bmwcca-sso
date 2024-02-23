<?php
namespace BMWCCA\SSO;

use Symfony\Component\HttpClient\HttpClient;

class Client
{
    public function __construct(protected string $integratorUrl, protected string $integratorUsername, protected string $integratorPassword)
    {
        //
    }

    public function memberNumberIsActive(string $memberNumber, $chapterId = 'BMWCCA')
    {
        try {
            $details = $this->retrieveDetailsByMemberNumber($memberNumber);
            return $details['memberships'][$chapterId]['status'] == 'ACTIVE';
        } catch (\Exception $e) {
            return false;
        }
    }

    public function retrieveUserByCredentials(string $username, string $password): array|bool
    {
        $xmlBody = <<<XML
<?xml version="1.0"?>
<authentication-request>
    <integratorUsername>$this->integratorUsername</integratorUsername>
    <integratorPassword>$this->integratorPassword</integratorPassword>
    <username>$username</username>
    <password><![CDATA[$password]]></password>
</authentication-request>
XML;
        $result = $this->doRequest('/CENSSAWEBSVCLIB.AUTHENTICATION', $xmlBody);
        if ($result['authenticated'] == 'true') {
            return [
                'last_name' => $result['customer']['name']['last-name'],
                'first_name' => $result['customer']['name']['first-name'],
                'email' => $result['customer']['cust-email'],
                'member_number' => $result['customer']['cust-id']
            ];
        }
        return false;
    }

    public function retrieveDetailsByMemberNumber(string $memberNumber, bool $includeInactive = false): array
    {
        $xmlBody = <<<XML
<?xml version="1.0"?>
<custInfoRequest>
    <custId>$memberNumber</custId>
    <integratorUsername>$this->integratorUsername</integratorUsername>
    <integratorPassword>$this->integratorPassword</integratorPassword>
    <bulkRequest>false</bulkRequest>
    <details includeCodeValues="true">
        <roles include="true" />
        <committeePositions include="true" includeInactive="$includeInactive" />
        <memberships include="true" includeInactive="$includeInactive" includeInactiveSlots="$includeInactive" />
     </details>
</custInfoRequest>
XML;

        $result = $this->doRequest('/CENSSAWEBSVCLIB.GET_CUST_INFO_XML', $xmlBody);
        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }
        if ($result) {
            $data = [
                'first_name' => $result['name']['firstName'],
                'last_name' => $result['name']['lastName'],
            ];

            $memberType = 'UNKNOWN';
            if (in_array('PRIMARY', $result['roles']['role'])) {
                $memberType = 'PRIMARY';
            } else if (in_array('ASSOCIATE', $result['roles']['role'])) {
                $memberType = 'ASSOCIATE';
            }
            $data['member_type'] = $memberType;

            foreach ($result['memberships']['membership'] as $membership) {
                $membershipData = [
                    'group_id' => $membership['subgroupId'],
                    'group_name' => $membership['subgroupName'],
                    'status' => $membership['statusCode'],
                    'expires' => $membership['expirationDate'],
                    'joined' => $membership['joinDate']
                ];
                $data['memberships'][$membershipData['group_id']] = $membershipData;
            }

            $data["committees"] = [];
            if (isset($result['committeePositions']['committeePosition'])) {
                $committeePositionList = $result['committeePositions']['committeePosition'];
                if (!isset($committeePositionList[0])) {
                    $committeePositionList = [$committeePositionList];
                }
                foreach ($committeePositionList as $committeePosition) {
                    $committeeData = [
                        'group_id' => $committeePosition['subgroupId'],
                        'group_name' => $committeePosition['subgroupName'],
                        'committee_type' => $committeePosition['committeeType'],
                        'committee_group_description' => $committeePosition['committeeGrpDescr'],
                        'committee_description' => $committeePosition['committeeDescr'],
                        'start_date' => $committeePosition['startDate'],
                        'position_type' => $committeePosition['positionCode'],
                        'position_description' => $committeePosition['positionDescr']
                    ];
                    $data['committees'][] = $committeeData;
                }
            }
            return $data;
        }
        return [];
    }

    protected function doRequest($url, $xmlBody)
    {
        $http = HttpClient::create();
        $postBody = ['P_INPUT_XML_DOC' => $xmlBody];
        $response = $http->request('POST', $this->integratorUrl . $url, ['body' => $postBody]);
        if ($response->getStatusCode() == 200) {
            $result = json_decode(json_encode(simplexml_load_string($response->getContent(), 'SimpleXMLElement', LIBXML_NOCDATA)), true);
            return $result;
        }
        throw new \RuntimeException($response->getStatusCode());
    }
}