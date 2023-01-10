<?php
class nyaa implements ISite, ISearch {
    const SITE = "https://nyaa.si/";
    
    /*
     * nyaa()
     * @param {string} $url
     * @param {string} $username
     * @param {string} $password
     */
    public function __construct($url = null, $username = null, $password = null, $meta = NULL) {
    }
    
    /*
     * UnitSize()
     * @param {string} $unit
     * @return {number} sizeof byte
     */
    static function UnitSize($unit) {
        switch ($unit) {
        case "KiB": return 1000;
        case "MiB": return 1000000;
        case "GiB": return 1000000000;
        case "TiB": return 1000000000000;
        default: return 1;
        }
    }
    
    static function Unzip($data) {
        $offset = (substr($data, 0, 2) == "\x1f\x8b") ? 2 : 0;
        if (substr($data, $offset, 1) == "\x08")  {
            return gzinflate(substr($data, $offset + 8));
        }
        return $data;
    }

    /*
     * ConvertCategoryToIndex()
     * @param {string} $category
     * @return {string} category index
     */
    static function ConvertCategoryToIndex($category) {
      switch ($category) {
        // Anime
        case "Anime": return "1_0";
        case "Anime - Anime Music Video": return "1_1";
        case "Anime - English-translated": return "1_2";
        case "Anime - Non-English-translated": return "1_3";
        case "Anime - Raw": return "1_4";

        // Audio
        case "Audio": return "2_0";
        case "Audio - Lossless": return "2_1";
        case "Audio - Lossy": return "2_2";

        // Literature
        case "Literature": return "3_0";
        case "Literature - English-translated": return "3_1";
        case "Literature - Non-English-translated": return "3_2";
        case "Literature - Raw": return "3_3";

        // Live Action
        case "Live Action": return "4_0";
        case "Live Action - English-translated": return "4_1";
        case "Live Action - Idol/Promotional Video": return "4_2";
        case "Live Action - Non-English-translated": return "4_3";
        case "Live Action - Raw": return "4_4";

        // Pictures
        case "Pictures": return "5_0";
        case "Pictures - Graphics": return "5_1";
        case "Pictures - Photos": return "5_2";

        // Software
        case "Software": return "6_0";
        case "Software - Applications": return "6_1";
        case "Software - Games": return "6_2";
        
        default: return "0_0";
      }
    }

    /*
     * Search()
     * @param {string} $keyword
     * @param {integer} $limit
     * @param {string} $category
     * @return {array} SearchLink array
     */
    public function Search($keyword, $limit, $category) {
        $page = 1;
        $ajax = new Ajax();
        $found = array();
        
        $success = function ($request, $header, $cookie, $body, $effective_url) use(&$page, &$found, &$limit) {
            // Extract the search result table
            preg_match(
                "`<table class=\"table table-bordered table-hover table-striped torrent-list\">.*</table>`siU",
                nyaa::Unzip($body),
                $body
            );
            
            if (!$body) {
                return ($page = false);
            }
            
            $body = html_entity_decode($body[0], ENT_QUOTES, "UTF-8");

            // Extract the tbody part of the search result table
            preg_match(
                "`<tbody>.*</tbody>`siU",
                $body,
                $tbody
            );

            // Extract all the row inside the tbody
            preg_match_all(
                "`<tr.*</tr>`siU",
                $tbody[0],
                $rows
            );
            
            if (!$rows || ($len = sizeof($rows[0])) == 0) {
                return ($page = false);
            }

            // Process each row inside the tbody
            for ($i = 0 ; $i < $len; ++$i) {

                // Extract the title column from current row
                preg_match(
                    "`<td colspan=\"2\">.*</td>`siU",
                    $rows[0][$i],
                    $title_area
                );

                // Get all the a tag from the title column
                preg_match_all(
                    "`<a.*</a>`siU",
                    $title_area[0],
                    $title_href
                );

                /* Generate the regex pattern for getting the necessary info from
                 * each row
                 *
                 * the (sizeof($title_href[0]) == 2 ?  "<a.*</a>.*" : "") condition is
                 * to judge whether there has a comment icon in the title column. If so,
                 * we need to get the content of the second a tag as the title, not the first
                 * one.
                 */
                $row_pattern = 
                    "`<tr.*".
                        "<td>.*".
                            "<a.* title=\"(?P<category>.*)\">.*".
                        "</td>.*".
                        "<td.*".
                            (sizeof($title_href[0]) == 2 ?  "<a.*</a>.*" : "").
                            "<a href=\"(?P<link>.*)\".*>(?P<name>.*)</a>.*".
                        "</td>.*".
                        "<td.*".
                            "<a.*".
                            "<a href=\"(?P<magnet>magnet:[^\"]*)\".*</a>.*".
                        "</td>.*".
                        "<td.*>(?P<size>.*).(?P<unit>[a-zA-Z]*)</td>.*".
                        "<td.*>(?P<time>.*)</td>.*".
                        "<td.*>(?P<seeders>\d+)</td>.*".
                        "<td.*>(?P<leechers>\d+)</td>.*".
                    "</tr>`siU";

                // Use the pattern generated above to extract the information needed by the download station
                preg_match(
                    $row_pattern,
                    $rows[0][$i],
                    $result
                );

                // Make the SearchLink by the info we just extract
                $tlink = new SearchLink;
                
                $tlink->src           = "Nyaa";
                $tlink->link          = $result["link"];
                $tlink->name          = $result["name"];
                $tlink->size          = floatval($result["size"]) * nyaa::UnitSize($result["unit"]);
                
                $time                 = explode(" ", strip_tags($result["time"]));
                $tlink->time          = DateTime::createFromFormat("Y-m-d H:i", "$time[0] $time[1]");
                
                $tlink->seeds         = $result["seeders"] + 0;
                $tlink->peers         = $result["leechers"] + 0;
                $tlink->category      = $result["category"];
                $tlink->enclosure_url = $result["magnet"];
                
                $found []= $tlink;
                
                if (count($found) >= $limit) {
                    return ($page = false);
                }
            }
            
            ++$page;
        };
        
        while ($page !== false && count($found) < $limit) {
            /*
             * API format
             * https://nyaa.si/?f=0&c=$category&q=$keyword&p=$page
             * the result page is compressed by gzip
             */
            $category = nyaa::ConvertCategoryToIndex($category);
            $request = array(
                "url"       => nyaa::SITE. "/?f=0&c=$category&q=$keyword&p=$page",
                "body"      => true
            );
            if (!$ajax->request($request, $success)) {
                break;
            }
        }
        
        return $found;
    }
}
?>
