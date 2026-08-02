<?php
require_once "functions.php";

#-----------------------------#
function token_panel($code_panel){

    $panel = select("marzban_panel","*","id",$code_panel,"select");
    if($panel['datelogin'] != null){
        $date = json_decode($panel['datelogin'],true);
        if(isset($date['time'])){
        $timecurrent = time();
        $start_date = time() - strtotime($date['time']);
        if($start_date <= 3600){
            return $date;
        }
        }
    }
    $url_get_token = $panel["url_panel"] . "/api/admin/token";
    $data_token = [
        "username" => $panel["username_panel"],
        "password" => $panel["password_panel"],
    ];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT_MS => 6000,
        CURLOPT_POSTFIELDS => http_build_query($data_token),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/x-www-form-urlencoded",
            "accept: application/json",
        ],
    ];
    $curl_token = curl_init($url_get_token);
    curl_setopt_array($curl_token, $options);
    $token = curl_exec($curl_token);
    if (curl_error($curl_token)) {
        $token = [];
        $token["errror"] = curl_error($curl_token);
        return $token;
    }
    curl_close($curl_token);

    $body = json_decode( $token, true);
    if(isset($body['access_token'])){
        $time = date('Y/m/d H:i:s');
        $data = json_encode(array(
            'time' => $time,
            'access_token' => $body['access_token']
            ));
        update("marzban_panel","datelogin",$data,'name_panel',$panel['name_panel']);
    }
    return $body;
}

#-----------------------------#

function getuser($usernameac, $location)
{
    $marzban_list_get = select(
        "marzban_panel",
        "*",
        "name_panel",
        $location,
        "select",
    );
    $Check_token = token_panel($marzban_list_get["id"]);
    $url = $marzban_list_get["url_panel"] . "/api/user/" . $usernameac;
    $header_value = "Bearer ";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: " . $header_value . $Check_token["access_token"],
    ]);

    $output = curl_exec($ch);
    curl_close($ch);
    $data_useer = json_decode($output, true);
    return $data_useer;
}
#-----------------------------#
function ResetUserDataUsage($usernameac, $location)
{
    $marzban_list_get = select(
        "marzban_panel",
        "*",
        "name_panel",
        $location,
        "select",
    );
    $Check_token = token_panel($marzban_list_get["id"]);
    $url =
        $marzban_list_get["url_panel"] . "/api/user/" . $usernameac . "/reset";
    $header_value = "Bearer ";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: " . $header_value . $Check_token["access_token"],
    ]);

    $output = curl_exec($ch);
    curl_close($ch);
    $data_useer = json_decode($output, true);
    return $data_useer;
}
#-----------------------------#
function adduser($username, $expire, $data_limit, $location, $is_test = false)
{
    $marzban_list_get = select(
        "marzban_panel",
        "*",
        "name_panel",
        $location,
        "select",
    );
    $Check_token = token_panel($marzban_list_get["id"]);
    $url = $marzban_list_get["url_panel"] . "/api/user";
    $header_value = "Bearer ";

    // Ensure default groups exist for newer Marzban versions (>0.8.4)
    ensure_default_groups($location);

    $data = [
        "proxies" => json_decode($marzban_list_get["proxies"]),
        "data_limit" => $data_limit,
        "username" => $username,
    ];

    // Add group assignment for Marzban >0.8.4 using group_ids
    if (is_marzban_version_above_084($location)) {
        $group_name = $is_test ? "mirza_test" : "mirza_paid";
        $groups = get_groups($location);
        $group_id = null;
        if (is_array($groups) && isset($groups["groups"])) {
            foreach ($groups["groups"] as $group) {
                if (
                    isset($group["name"]) &&
                    $group["name"] === $group_name &&
                    isset($group["id"])
                ) {
                    $group_id = $group["id"];
                    break;
                }
            }
        }
        if ($group_id !== null) {
            $data["group_ids"] = [$group_id];
        }
    }

    if (
        $marzban_list_get["inbounds"] != null and
        $marzban_list_get["inbounds"] != "null"
    ) {
        $data["inbounds"] = json_decode($marzban_list_get["inbounds"], true);
    }
    if ($expire == "0") {
        $data["expire"] = 0;
    } else {
        if ($marzban_list_get["onholdstatus"] == "ononhold") {
            $data["expire"] = 0;
            $data["status"] = "on_hold";
            $data["on_hold_expire_duration"] = $expire - time();
        } else {
            $data["expire"] = $expire;
        }
    }
    $payload = json_encode($data);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: " . $header_value . $Check_token["access_token"],
        "Content-Type: application/json",
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}
//----------------------------------
function Get_System_Stats($location)
{
    $marzban_list_get = select(
        "marzban_panel",
        "*",
        "name_panel",
        $location,
        "select",
    );
    $Check_token = token_panel($marzban_list_get["id"]);
    $url = $marzban_list_get["url_panel"] . "/api/system";
    $header_value = "Bearer ";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: " . $header_value . $Check_token["access_token"],
    ]);

    $output = curl_exec($ch);
    curl_close($ch);
    $Get_System_Stats = json_decode($output, true);
    return $Get_System_Stats;
}
//----------------------------------
function removeuser($location, $username)
{
    $marzban_list_get = select(
        "marzban_panel",
        "*",
        "name_panel",
        $location,
        "select",
    );
    $Check_token = token_panel($marzban_list_get["id"]);
    $url = $marzban_list_get["url_panel"] . "/api/user/" . $username;
    $header_value = "Bearer ";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: " . $header_value . $Check_token["access_token"],
    ]);

    $output = curl_exec($ch);
    curl_close($ch);
    $data_useer = json_decode($output, true);
    return $data_useer;
}
//----------------------------------
function Modifyuser($location, $username, array $data)
{
    $marzban_list_get = select(
        "marzban_panel",
        "*",
        "name_panel",
        $location,
        "select",
    );
    $Check_token = token_panel($marzban_list_get["id"]);
    $url = $marzban_list_get["url_panel"] . "/api/user/" . $username;
    $payload = json_encode($data);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $headers = [];
    $headers[] = "Accept: application/json";
    $headers[] = "Authorization: Bearer " . $Check_token["access_token"];
    $headers[] = "Content-Type: application/json";
    $headers[] = "x-bot-secret" . ": " . $SECRET;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    curl_close($ch);
    $data_useer = json_decode($result, true);
    return $data_useer;
}

#-----------------------------------------------#
function revoke_sub($username, $location)
{
    global $connect, $SECRET;
    $marzban_list_get = select(
        "marzban_panel",
        "*",
        "name_panel",
        $location,
        "select",
    );
    $Check_token = token_panel($marzban_list_get["id"]);
    $usernameac = $username;
    $url =
        $marzban_list_get["url_panel"] .
        "/api/user/" .
        $usernameac .
        "/revoke_sub";
    $header_value = "Bearer ";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: " . $header_value . $Check_token["access_token"],
    ]);

    $output = curl_exec($ch);
    curl_close($ch);
    $data_useer = json_decode($output, true);
    return $data_useer;
}

//----------------------------------
// Check if Marzban version is above 0.8.4
function is_marzban_version_above_084($location)
{
    try {
        $system_stats = Get_System_Stats($location);
        if (isset($system_stats["version"])) {
            $version = $system_stats["version"];
            // Extract numeric version part (e.g., from 'beta 1.0.0' get '1.0.0')
            if (preg_match("/(\d+\.\d+\.\d+)/", $version, $matches)) {
                $numeric_version = $matches[1];
            } else {
                $numeric_version = $version; // fallback if no match
            }
            return version_compare($numeric_version, "0.8.4", ">");
        }
    } catch (Exception $e) {
        error_log("Error checking Marzban version: " . $e->getMessage());
    }
    return false;
}

//----------------------------------
// Get all available inbounds for a panel
function get_all_inbounds($location)
{
    $marzban_list_get = select(
        "marzban_panel",
        "*",
        "name_panel",
        $location,
        "select",
    );
    $Check_token = token_panel($marzban_list_get["id"]);
    $url = $marzban_list_get["url_panel"] . "/api/inbounds";
    $header_value = "Bearer ";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: " . $header_value . $Check_token["access_token"],
    ]);

    $output = curl_exec($ch);
    curl_close($ch);
    $inbounds = json_decode($output, true);

    // Extract inbound tags
    $inbound_tags = [];
    if (is_array($inbounds)) {
        foreach ($inbounds as $inbound) {
            if (isset($inbound["tag"])) {
                $inbound_tags[] = $inbound["tag"];
            }
        }
    }

    return $inbound_tags;
}

//----------------------------------
// NEW: Fetch inbound tags via /api/cores (more reliable for newer Marzban versions)
function get_inbound_tags_from_cores($location)
{
    $marzban_list_get = select(
        "marzban_panel",
        "*",
        "name_panel",
        $location,
        "select",
    );
    $Check_token = token_panel($marzban_list_get["id"]);
    $url = rtrim($marzban_list_get["url_panel"], "/") . "/api/cores";
    $header_value = "Bearer ";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: " . $header_value . $Check_token["access_token"],
    ]);

    $output = curl_exec($ch);
    curl_close($ch);

    $cores_data = json_decode($output, true);
    $all_inbound_tags = [];

    if (isset($cores_data["cores"]) && is_array($cores_data["cores"])) {
        foreach ($cores_data["cores"] as $core) {
            if (
                isset($core["config"]["inbounds"]) &&
                is_array($core["config"]["inbounds"])
            ) {
                foreach ($core["config"]["inbounds"] as $inbound) {
                    if (isset($inbound["tag"])) {
                        $all_inbound_tags[] = $inbound["tag"];
                    }
                }
            }
        }
    }
    $all_inbound_tags = array_values(array_unique($all_inbound_tags));
    return $all_inbound_tags;
}

//----------------------------------
// Helper: normalize groups response and return array of group objects
function _extract_groups_array($existing_groups)
{
    if (is_array($existing_groups)) {
        if (
            isset($existing_groups["groups"]) &&
            is_array($existing_groups["groups"])
        ) {
            return $existing_groups["groups"];
        }
        // If the response itself is the list (numeric keys)
        $is_numeric_indexed = true;
        foreach (array_keys($existing_groups) as $k) {
            if (!is_int($k)) {
                $is_numeric_indexed = false;
                break;
            }
        }
        if ($is_numeric_indexed) {
            return $existing_groups;
        }
    }
    return [];
}

// Helper: get single group by name (case-insensitive)
function get_group_by_name($location, $group_name)
{
    $existing_groups = get_groups($location);
    $groups_list = _extract_groups_array($existing_groups);
    foreach ($groups_list as $g) {
        if (isset($g["name"]) && strcasecmp($g["name"], $group_name) === 0) {
            return $g;
        }
    }
    return null;
}

// Create a group in Marzban (updated to auto-populate inbound_tags like working sample)
function create_group($location, $group_name, $inbound_tags = null)
{
    // Avoid duplicate creation attempt if it already exists
    $existing = get_group_by_name($location, $group_name);
    if ($existing !== null) {
        return $existing; // Return existing group instead of creating new duplicate
    }
    $marzban_list_get = select(
        "marzban_panel",
        "*",
        "name_panel",
        $location,
        "select",
    );
    $Check_token = token_panel($marzban_list_get["id"]);
    $url = rtrim($marzban_list_get["url_panel"], "/") . "/api/group";
    $header_value = "Bearer ";

    // If no inbound tags explicitly passed, try to fetch from /api/cores first, then fallback to previous method
    if (
        $inbound_tags === null ||
        !is_array($inbound_tags) ||
        count($inbound_tags) === 0
    ) {
        $inbound_tags = get_inbound_tags_from_cores($location);
        if (count($inbound_tags) === 0) {
            // Fallback to old /api/inbounds endpoint if cores returned nothing
            $inbound_tags = get_all_inbounds($location);
        }
    }

    $data = [
        "name" => $group_name,
    ];

    if (is_array($inbound_tags) && count($inbound_tags) > 0) {
        $data["inbound_tags"] = array_values($inbound_tags);
    }

    $payload = json_encode($data);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: " . $header_value . $Check_token["access_token"],
        "Content-Type: application/json",
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Basic debug logging (can be removed/adjusted)
    if ($http_code !== 200 && $http_code !== 201) {
        error_log(
            "create_group failed: HTTP " .
                $http_code .
                " response: " .
                $response,
        );
    }

    return json_decode($response, true);
}

//----------------------------------
// Get all groups from Marzban
function get_groups($location)
{
    $marzban_list_get = select(
        "marzban_panel",
        "*",
        "name_panel",
        $location,
        "select",
    );
    $Check_token = token_panel($marzban_list_get["id"]);
    $url = $marzban_list_get["url_panel"] . "/api/groups";
    $header_value = "Bearer ";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: " . $header_value . $Check_token["access_token"],
    ]);

    $output = curl_exec($ch);
    curl_close($ch);

    return json_decode($output, true);
}

//----------------------------------
// Ensure default groups exist for Marzban >0.8.4 (now passes inbound_tags) (updated to avoid duplicates)
function ensure_default_groups($location)
{
    static $already_ran = [];
    if (isset($already_ran[$location])) {
        return [];
    }
    $already_ran[$location] = true; // guard for repeated calls within same request
    try {
        if (!is_marzban_version_above_084($location)) {
            return false; // Not needed for older versions
        }

        $existing_groups_resp = get_groups($location);
        $groups_list = _extract_groups_array($existing_groups_resp);
        $group_names = [];
        foreach ($groups_list as $group) {
            if (isset($group["name"])) {
                $group_names[] = strtolower($group["name"]); // case-insensitive tracking
            }
        }

        $required_groups = ["mirza_paid", "mirza_test"];
        $created_groups = [];

        // Fetch inbound tags once (reuse)
        $inbound_tags = get_inbound_tags_from_cores($location);
        if (count($inbound_tags) === 0) {
            $inbound_tags = get_all_inbounds($location);
        }

        foreach ($required_groups as $group_name) {
            if (!in_array(strtolower($group_name), $group_names, true)) {
                $result = create_group($location, $group_name, $inbound_tags);
                if (isset($result["name"])) {
                    $created_groups[] = $result["name"];
                    $group_names[] = strtolower($result["name"]); // update local cache
                } else {
                    error_log(
                        "Failed creating group " .
                            $group_name .
                            " response: " .
                            json_encode($result),
                    );
                }
            }
        }

        return $created_groups;
    } catch (Exception $e) {
        error_log("Error ensuring default groups: " . $e->getMessage());
        return false;
    }
}

/**
 * How many usernames to request per Marzban bulk-fetch HTTP call (see
 * bulkFetchMarzbanUsers() below). Deliberately independent of how many
 * total users live on the panel - we only ever ask for usernames the
 * caller actually needs, chunked to keep each request's query string and
 * response payload small and predictable. This is what keeps a panel with
 * thousands of users from ever being fetched "all at once".
 */
const MARZBAN_BULK_CHUNK_SIZE = 150;

/**
 * Bulk fetch for Marzban panels.
 *
 * Marzban's GET /api/users accepts a repeatable `username` query parameter
 * that returns only the requested usernames in one response. That lets a
 * caller fetch exactly the usernames it needs instead of:
 *   - fetching the panel's ENTIRE user list (a busy panel can have
 *     thousands of users - large payload, slow response, mostly wasted), or
 *   - one HTTP call per username.
 *
 * Requests are chunked by MARZBAN_BULK_CHUNK_SIZE so the amount of work per
 * call tracks the number of usernames asked for, not panel size.
 *
 * Marzban-only: the `username` array filter is confirmed for Marzban's API
 * but not for other panel types, which keep using the per-user
 * ManagePanel::DataUser() path.
 *
 * @param array $panel     Row from `marzban_panel` (needs id + url_panel).
 * @param array $usernames Usernames to fetch (already de-duplicated).
 * @return array<string, array{status:string, expire:?int, data_limit:?int, used_traffic:?int}>
 *         Keyed by username; missing/unresolved usernames simply won't have
 *         a key, which callers treat the same as "not found".
 */
function bulkFetchMarzbanUsers(array $panel, array $usernames): array
{
    $result = [];
    if (empty($usernames)) {
        return $result;
    }

    $token = token_panel($panel['id']);
    if (!isset($token['access_token'])) {
        // Auth failure - leave all usernames unresolved rather than falling
        // back to per-user calls that would hit the same auth problem N times.
        return $result;
    }

    foreach (array_chunk($usernames, MARZBAN_BULK_CHUNK_SIZE) as $chunk) {
        $queryParts = ['limit=' . count($chunk)];
        foreach ($chunk as $username) {
            $queryParts[] = 'username=' . rawurlencode($username);
        }
        $url = rtrim($panel['url_panel'], '/') . '/api/users?' . implode('&', $queryParts);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 8000,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token['access_token'],
            ],
        ]);
        $output = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || !$output) {
            // Network hiccup for this chunk only; those usernames stay unresolved.
            continue;
        }

        $body = json_decode($output, true);
        if (!isset($body['users']) || !is_array($body['users'])) {
            continue;
        }

        foreach ($body['users'] as $u) {
            if (!isset($u['username'])) {
                continue;
            }
            $result[$u['username']] = [
                'status'       => $u['status'] ?? '',
                'expire'       => $u['expire'] ?? null,
                'data_limit'   => $u['data_limit'] ?? null,
                'used_traffic' => $u['used_traffic'] ?? null,
            ];
        }
    }

    return $result;
}
