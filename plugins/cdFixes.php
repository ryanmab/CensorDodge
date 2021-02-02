<?php
function DailyMotion_preParse(&$page, $URL, $proxy) {
	if(preg_match('/video\/([^_]+)/', $URL, $matches)) { //Check if DailyMotion URL is a video
		$html = $proxy->curlRequest("http://www.dailymotion.com/embed/video/".$matches[1])["page"]; //Get basic embed video source

		if(preg_match_all('#type":"video\\\/mp4","url":"([^"]+)"#is', $html, $matches) && !$proxy->stripObjects) {
			$url = stripslashes(end($matches[1])); //Find the best available video source

			//Build and insert basic video element into page which users can watch
			$randPlayerID = substr(md5(rand(0,500)),0,10);
			$html = '<video style="width:100%;height:100%;" autoplay controls id="'.$randPlayerID.'"><source type="video/mp4" src="'.$url.'"></video>';
			$page = preg_replace('#<div class="player-container">.*?</div>#s', '<div class="player_container" style="width:880px; height:495px;">'.$html.'</div>', $page, 1);
		}
	}
}

function YouTube_preRequest($page, $URL, $proxy) {
	//Force YouTube to disable the polymer design
	parse_str(parse_url($URL, PHP_URL_QUERY), $queries);
	if (strpos($URL, "disable_polymer=1")===false) {
		$queries = array_merge($queries, array("disable_polymer" => "1"));
		$proxy->setURL(explode("?", $URL)[0]."?".http_build_query($queries));
	}

    $proxy->customUserAgent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; nl; rv:1.8)';

	//Add query string to migrate any mobile users to desktop app
	if (preg_match("/\/m.youtube.[a-zA-z]+/i", $URL)) {
		$queries = array_merge($queries, array("app" => "desktop", "persist_app" => "1", "noapp" => "1"));
		$proxy->setURL(explode("?",preg_replace("/m.youtube/i","youtube",$URL,1))[0]."?".http_build_query($queries));
	}
}

function YouTube_preParse(&$page, $URL, $proxy) {
    if ($proxy->stripObjects) { return; }
    $html = ''; $group = substr(md5(rand(0,500)),0,10);
    $sources = decodeSources($page, $proxy);
    if (isset($sources["video"])) {
        $html .= '<video style="width:100%;height:100%;" autoplay controls id="'.$group.'">';
        foreach ($sources["video"] as $video) {
            $html .= "<source src=\"".$video["URL"]."\" ".(!empty($video["type"]) ? "type=\"".$video["type"]."\" " : "")."/>";
        }
        $html .= '</video>';
    }
    else {
        $html = "<p>Unable to decode video URL!</p>";
    }

    $page = preg_replace('~<div id="player-api"([^>]*)>.*?</div>~is', '<div id="updated-player"$1>'.$html."</div>", $page);
    $page = str_replace(array("a=Ba().contentWindow.history.pushState,\"function\"==typeof a","\"function\"==typeof c"),"false",$page);
}

function decodeSources(&$page, $proxy) {
    preg_match_all('@(url_encoded_fmt_stream_map|cipher)(\\\\|)["\']:[\\\\\s]*["\'](.*)(\\\\|)["\']@U', $page, $encodedStreamMap);
	$decodedMaps = array();
    $sources = array(); //Find all video URLs
    if (isset($encodedStreamMap[3]) && count($encodedStreamMap[3])>0) {
        foreach ($encodedStreamMap[3] as $map) {
            $p = count($decodedMaps)+1;
            foreach (explode(strpos($map, '\\\u0026') !== false ? '\\\u0026' : '\u0026', $map) as $arg) {
                $split = explode("=", $arg);
                $decodedMaps[$p][$split[0]] = urldecode($split[1]);
            }
        }
    }
    else {
        preg_match('@formats\\\\["\']:\[{\\\\(["\'].*["\'])}\]@', $page, $encodedStreamMap);
        foreach(explode("},{",$encodedStreamMap[1]."}") as $map) {
            $map = "{".str_replace("\\\\u0026","&",str_replace("\\\"","\"",str_replace("\\\\\\\"","'",$map)))."}";
            $map = get_object_vars(json_decode($map));
            if (isset($map["itag"])) {
                $decodedMaps[] = $map;
            }
        }
    }

    if (count($decodedMaps)>0) {
        foreach($decodedMaps as $data) {
            if (isset($data["signatureCipher"])) { parse_str($data["signatureCipher"], $ciper); $data = array_merge($data, $ciper); }
            $u = $data['url']; $type = explode(";",!isset($data["type"]) ? $data["mimeType"] : $data["type"],2)[0];

            //Process signature decoding to produce valid URLs
            if (isset($data['sig'])) {
                $u = $u.'&signature=' . $data['sig'];
            }
            elseif (isset($data['signature'])) {
                $u = $u.'&signature='.$data['signature'];
            }
            elseif (isset($data['s'])) {
                if ((!isset($playerHTML) || !is_string($playerHTML)) && preg_match('@<script\s*src="([^"]+player[^"]+js)@', $page, $matches)) {
                    $playerHTML = $proxy->curlRequest($proxy->modifyURL($matches[1]))["page"];
                }

                if (isset($playerHTML) && is_string($playerHTML)) {
                    $signature = decodeSignature($data['s'], $playerHTML);
                    if (is_null($signature)) { $u = null; continue; }
                    $u = $u.'&'.$data['sp'].'='.$signature;
                }
                else {
                    continue;
                }
            }
            if (!is_null($u)) { $sources[explode("/",empty($type) ? "video" : $type,2)[0]][] = array("URL" => $u, "type" => $type, "quality" => $data["quality"], "qualityLabel" => $data["qualityLabel"]); }
        }
    }

    return $sources;
}

function decodeSignature($signature, $playerHTML) {
    if (preg_match('@,\s*encodeURIComponent\((\w{2})@is', $playerHTML, $matches)) {
        $functionName = preg_quote($matches[1]);
    } else if (preg_match('@\b([a-zA-Z0-9$]{2})\s*=\s*function\(\s*a\s*\)\s*{\s*a\s*=\s*a\.split\(\s*""\s*\)@is', $playerHTML, $matches)) {
        $functionName = preg_quote($matches[1]);
    }
    else {
        return false;
    }

    $instructions = getSigDecodeInstructions($playerHTML, $functionName); //Get decode instructions

    if (count($instructions)>0) {
        foreach ($instructions as $opt) {
            switch ($opt[0]) {
                case 'swap':
                    $temp = $signature[0];
                    $signature[0] = $signature[$opt[1] % strlen($signature)];
                    $signature[$opt[1] % strlen($signature)] = $temp;
                    break;

                case 'splice':
                    $signature = substr($signature, $opt[1]);
                    break;

                case 'reverse':
                    $signature = strrev($signature);
                    break;
            }
        }

        return trim($signature);
    }

	return null;
}

function getSigDecodeInstructions($playerHTML, $functionName) {
	if (preg_match('/' . $functionName . '=function\([a-z]+\){(.*?)}/', $playerHTML, $matches)) {
		//Extract statements from block
		if (preg_match_all('/([a-z0-9]{2})\.([a-z0-9]{2})\([^,]+,(\d+)\)/i', $matches[1], $matches) != false) {
			$functionList = $matches[2];

			preg_match_all('/('.implode('|', $functionList).'):function\([a-z,]+\){(.*?)\}/m', $playerHTML, $functionDefinitions, PREG_SET_ORDER);
			$functions = array();

			//Translate each function according to its use
			foreach ($functionDefinitions as $f) {
				if (strpos($f[2], 'splice') !== false) {
					$functions[$f[1]] = 'splice';
				} elseif (strpos($f[2], 'a.length') !== false) {
					$functions[$f[1]] = 'swap';
				} elseif (strpos($f[2], 'reverse') !== false) {
					$functions[$f[1]] = 'reverse';
				}
			}

			$instructions = array();
			foreach ($matches[2] as $index => $name) {
				$instructions[] = array($functions[$name], $matches[3][$index]);
			}

			return $instructions;
		}
	}

	return null;
}

if (class_exists("censorDodge")) { //Check that the class is accessible to add the function hooks
	censorDodge::addAction("DailyMotion_preParse","preParse","#dailymotion.[a-zA-z.]+#i");
	censorDodge::addAction("YouTube_preRequest","preRequest","#youtube.[a-zA-z.]+#i");
	censorDodge::addAction("YouTube_preParse","preParse","#youtube.[a-zA-z.]+#i");
}
