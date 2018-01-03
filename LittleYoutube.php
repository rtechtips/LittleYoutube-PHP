<?php

	/***
		LittleYoutube Library v1.0
		https://github.com/StefansArya/LittleYoutube-PHP
		
		This is a free library. You can redistribute it and/or modify
		it under the terms of the GNU General Public License as published by
		the Free Software Foundation.
		
		You should have include this small notice along this script.
	***/

namespace ScarletsFiction\LittleYoutube{
	class LittleYoutubeInfo{
		public $error;
		public $data;
		public $settings = [];
		protected $classData;

		public function __construct($id, $options)
		{
			$this->resetInit();
			if($options)
				$this->settings = array_replace($this->settings, $options);
			$this->settings['temporaryDirectory'] = $this->settings['temporaryDirectory'].DIRECTORY_SEPARATOR;
			if($this->settings['temporaryDirectory']==DIRECTORY_SEPARATOR){
				$this->error = "Can't find temporary folder";
			} elseif(!is_writable($this->settings['temporaryDirectory'])){
				$this->error = "Temporary isn't writeable - change the folder permission to 777";
			}

			$this->init($id);
		}

		public function resetInit()
		{
			$this->classData = [];
			$this->data = [];
			if(!$this->settings)
				$this->settings = [
					"temporaryDirectory"=>realpath(__DIR__."/example/temp"),
					"signatureDebug"=>false,
					"processDetail"=>true,  // Set it to false if you don't need to download the video data
					"useRedirector"=>false, // Optional if the video can't be downloaded on some country
					"loadVideoSize"=>false, // Would cause slow down because all video format will be checked
					"processVideoFrom"=>"VideoPage" // Parse from VideoInfo or VideoPage
				];
		}
	}

	class Video extends LittleYoutubeInfo
	{
		public function init($url)
		{
			$id = $url;
			if(strpos($id, '/watch?v=')!==false){
				$id = explode('/watch?v=', $id)[1];
				if(strpos($id, '#')!==false){
					$id = explode('#', $id)[0];
				}
				if(strpos($id, '&')!==false){
					$id = explode('&', $id)[0];
				}
			}
			else if(strpos($id, 'youtu.be/')!==false){
				$id = explode('youtu.be/', $id)[1];
				$id = explode('?', $id)[0];
			}
			$this->data['videoID'] = $id;
			if($this->settings["processDetail"])
				$this->processDetails();
		}

		public function processDetails()
		{
			if(isset($this->data['videoID'])) $id = $this->data['videoID'];
			else{
				$this->error = "No videoID";
				return false;
			}

			if($this->settings['processVideoFrom']=='VideoPage'){
				$data = \ScarletsFiction\WebApi::loadURL('https://www.youtube.com/watch?v='.$id)['content'];
				if(strpos($data, 'Sorry for the interruption')!==false){
					$this->error = "Need to solve captcha from youtube";
					return false;
				}
				$data = explode('ytplayer.config = ', $data)[1];
				$data = explode(';ytplayer.load', $data);
				$data = $data[0];
				$data = json_decode($data, true);
				unset($data['args']['fflags']);
				//$this->parseVideoDetail($data);

				$this->getPlayerScript($data['assets']['js']);
				if(!isset($data['args']['title'])){
					$this->error = "Video not exist";
					return false;
				}
				$data = $data['args'];
			}
			elseif($this->settings['processVideoFrom']=='VideoInfo'){
				$data_ = \ScarletsFiction\WebApi::loadURL('http://www.youtube.com/get_video_info?video_id='.$id."&el=detailpage&hl=en_US")['content'];
				parse_str($data_, $data);
			}

			$this->parseVideoInfo($data);
		}

		private function parseVideoInfo($data){
			$this->data['title'] = $data['title'];
			$this->data['duration'] = $data['length_seconds'];
			$this->data['viewCount'] = $data['view_count'];
			$this->data['channelID'] = $data['ucid'];

			$subtitle = json_decode($data['player_response'], true);
			if(isset($subtitle['captions'])&&isset($subtitle['captions']['playerCaptionsTracklistRenderer']['captionTracks'])){
				$this->data['subtitle'] = $subtitle['captions']['playerCaptionsTracklistRenderer']['captionTracks'];
				foreach ($this->data['subtitle'] as &$value) {
					$value = ['url'=>$value['baseUrl'], 'lang'=>$value['languageCode']];
				}
			} else $this->data['subtitle'] = false;
			if(isset($data['livestream'])&&$data['livestream']){
				$this->data['video'] = ["stream"=>$data['hlsvp']];
				$this->data['uploaded'] = "Live Now!";
			}
			else{
				$streamMap = [[],[]];
				if(isset($data['url_encoded_fmt_stream_map'])){
					$streamMap[0] = explode(',', $data['url_encoded_fmt_stream_map']);
					if(count($streamMap[0])) $streamMap[0] =  $this->streamMapToArray($streamMap[0]);
				}
				if(isset($data['adaptive_fmts'])){
					$streamMap[1] = explode(',', $data['adaptive_fmts']);
					if(count($streamMap[1])) $streamMap[1] =  $this->streamMapToArray($streamMap[1]);
				}
				$this->data['video'] = ["encoded"=>$streamMap[0], "adaptive"=>$streamMap[1]];
			}
		}

		private function parseVideoDetail($data){
			$panelDetails = explode('"action-panel-details"', $data);
			if(count($panelDetails)==1){
				$this->error = "Failed to parse video details";
				file_put_contents($this->settings['temporaryDirectory'].'error.log', $data);
				return false;
			}

			$userData = explode('photo.jpg', $panelDetails[0]);
			$userData[0] = explode('"', $userData[0]);
			$userData[0] = $userData[0][count($userData[0])-1].'photo.jpg';
			$userData[1] = explode('alt="', $panelDetails[0])[1];
			$userData[1] = explode('"', $userData[1])[0];
			$this->data['userData'] = [
				"name"=>$userData[1],
				"image"=>$userData[0]
			];
			$this->data['userID'] = explode('/', explode('?', explode('"', explode('/user/', $panelDetails[0])[1])[0])[0])[0];

			$panelDetails = $panelDetails[1];
			$panelDetails = explode("<button", $panelDetails)[0];

			$uploaded = explode('"watch-time-text">', $panelDetails);
			if(count($uploaded)!=1){
				$uploaded = explode('</strong>', $uploaded[1])[0];
				$this->data['uploaded'] = $uploaded;
			}

			$description = explode('"eow-description"', $panelDetails)[1];
			$description = str_replace(['<br />', '<br/>', '<br>'], "\n", $description);
			$description = strip_tags('<p'.explode('</p>', $description)[0]);
			$this->data['description'] = $description;
			
			$metatag = trim('<'.explode('"watch-extras-section"', $panelDetails)[1]);
			$metatag = str_replace('  ', '', $metatag);
			$metatag = explode("<h4 class=\"title\">\n", $metatag);
			unset($metatag[0]);
			$metatag = array_values($metatag);
			foreach ($metatag as &$value) {
				$value = str_replace("\n\n\n", ': ', trim(strip_tags($value)));
			}
			$this->data['metatag'] = $metatag;
			
			$likeDetails = explode('"like-button-renderer', $data)[1];
			$likeDetails = explode('dislike-button-clicked yt-uix-button-toggled', $likeDetails)[0];
			$likeDetails = explode('like-button-unclicked', $likeDetails);
			unset($likeDetails[0]);
			foreach ($likeDetails as &$value) {
				$value = explode('button-content">', $value)[1];
				$value = explode('</span>', $value)[0];
			}
			$this->data['like'] = str_replace('.', '', $likeDetails[1]);
			$this->data['dislike'] = str_replace('.', '', $likeDetails[2]);
		}

		private function streamMapToArray($streamMap)
		{
			foreach($streamMap as &$map)
			{
				parse_str($map, $map_info);
				parse_str(urldecode($map_info['url']), $url_info);

				$map = [];
				$map['itag'] = $map_info['itag'];
				$map['type'] = explode(';', $map_info['type']);
				$format = explode('/', $map['type'][0]);
				$encoder = explode('"', $map['type'][1])[1];
				$map['type'] = array_merge($format, [$encoder]);
				$map['expire'] = isset($url_info['expire'])?$url_info['expire']:0;

				if(isset($map_info['bitrate']))
					$map['quality'] = isset($map_info['quality_label'])?$map_info['quality_label']:round($map_info['bitrate']/1000).'k';
				else
					$map['quality'] = isset($map_info['quality'])?$map_info['quality']:'';
		
				$signature = '';

				// The video signature need to be deciphered
				if(isset($map_info['s']))
				{
					if(strpos($map_info['url'], 'ratebypass=')===false)
						$map_info['url'] .= '&ratebypass=yes';

					// Renew decipher script if first try was failed
					for($i=0; $i < 2; $i++){
		  				$signature = '&signature='.$this->decipherSignature($map_info['s'], $i);
		  				$map['url'] = $map_info['url'].$signature.'&title='.urlencode($this->data['title']);
						
						if($this->settings['loadVideoSize']){
							$size = \ScarletsFiction\WebApi::urlContentSize($map['url']);
							$map['size'] = \ScarletsFiction\FileApi::fileSize($size);
							if($map['size']!=0)
								break;
						} else break;
					}
		
					if($this->settings['useRedirector']){
						//Change to redirector
						$subdomain = explode(".googlevideo.com", $map_info['url'])[0];
						$subdomain = explode("//", $subdomain)[1];
						$map_info['url'] = str_replace($subdomain, 'redirector', $map_info['url']);
					}
				}
			}
			return $streamMap;
		}

		public function getEmbedLink(){
			if(!isset($this->data['videoID'])){
				$this->error = "videoID was not found";
				return false;
			}
			return "//www.youtube.com/embed/".$this->data['videoID']."?rel=0";
		}

		public function parseSubtitle($idOrXML=false, $asSRT=false){
			if(is_string($idOrXML)){
				$data = $idOrXML;
			}else{
				if(!isset($this->data['subtitle'][$idOrXML])){
					$this->error = "No subtitle found";
					return false;
				}
				$data = \ScarletsFiction\WebApi::loadURL($this->data['subtitle'][$idOrXML]['url'])['content'];
			}
			if(!$data) return false;

			$data = str_replace(['</transcript>', '</text>'], '', $data);
			$data = explode('<text ', $data);
			unset($data[0]); $data = array_values($data);

			foreach ($data as &$value) {
				$value = explode('>', $value);
				$value = ["when"=>$value[0], "text"=>strip_tags(html_entity_decode($value[1]))];
				$value['time'] = explode('"', explode('start="', $value['when'])[1])[0];
				$value['duration'] = explode('"', explode('dur="', $value['when'])[1])[0];
				unset($value['when']);
			}
			if($asSRT){
				$srt = '';
				for($i=0; $i<count($data); $i++){
					$srt .= "\n".($i+1)."\n";
					$srt .= gmdate("H:i:s,", $data[$i]['time']).floor(fmod($data[$i]['time'], 1)*1000);
					$sum = $data[$i]['time']+$data[$i]['duration'];
					$srt .= ' --> '.gmdate("H:i:s,", $sum).floor(fmod($sum, 1)*1000);
					$srt .= "\n".$data[$i]['text'];
					if($data[$i+1]['time']<$sum){
						$srt .= ' '.$data[$i+1]['text'];
						$i++;
					}
					$srt .= "\n";
				}
				return $srt;
			}
			return $data;
		}
		
		public function getImage()
		{
			$id = $this->data['videoID'];
			return [
			//High Quality Thumbnail (480x360px)
				"http://i1.ytimg.com/vi/$id/hqdefault.jpg",
			//Medium Quality Thumbnail (320x180px)
				"http://i1.ytimg.com/vi/$id/mqdefault.jpg",
			//Normal Quality Thumbnail (120x90px)
				"http://i1.ytimg.com/vi/$id/default.jpg"
			];
		}

		private function getLatestPlayerScript($forceReset){
			if(!$forceReset){
				if(isset($this->data['signature'])&&isset($this->data['signature']['patterns'])){
					return; // Decipher already loaded
				}

				elseif(!isset($this->data['signature'])||!isset($this->data['signature']['patterns'])){
					// Load decipher from file
					$decipherPath = $this->settings['temporaryDirectory'].'decipherData.inf';
					if(file_exists($decipherPath)&&filemtime($decipherPath)>=(time()-21600)){
						$this->classData['signature'] = json_decode(file_get_contents($this->settings['temporaryDirectory'].'decipherData.inf'), true);
						return;
					}
				}
				// Else -> continue with parse old script if exist
			}
			else{ // Force reset by redownloading player script
				$this->getPlayerScript(false, $this->data['videoID']);
				$this->getSignatureParser();
				return;
			}
			// If current player id is set, then load from it
			if(isset($this->data['playerID'])){
				if(!file_exists($this->settings['temporaryDirectory'].$this->data['playerID'].'.js')){
					$this->getSignatureParser();
					return;
				}
			}

			// Find newest player script file
			$files = glob($this->settings['temporaryDirectory']."*.js");
			if(count($files)==0){
				$this->getPlayerScript(false, $this->data['videoID']);
				$this->getSignatureParser();
				return;
			}
			$last = [];
			for ($i=0; $i < count($files); $i++){
				if(filesize($files[$i])>1000000){
					$ftime = filemtime($files[$i]);
					if($ftime>=(time()-21600)){
						$last[$files[$i]] = $ftime;
						continue;
					} 
				}
				unlink($files[$i]);
			}
			arsort($last);

			foreach ($last as $key => $value) {
				$this->data['playerID'] = explode(DIRECTORY_SEPARATOR, explode('.js', $key)[0]);
				$this->data['playerID'] = $this->data['playerID'][count($this->data['playerID'])-1];
				$this->getSignatureParser();
				return;
			}
		}

		private function getPlayerScript($playerURL, $fromVideoID=false){
			if($fromVideoID){
				$data = \ScarletsFiction\WebApi::loadURL('https://www.youtube.com/watch?v='.$fromVideoID)['content'];
				if(strpos($data, 'Sorry for the interruption')!==false){
					$this->error = "Need to solve captcha from youtube";
					return false;
				}
				$data = explode("/yts/jsbin/player", $data)[1];
				$data = explode('"', $data)[0];
				$playerURL = "/yts/jsbin/player".$data;
			}
			try{
				$playerID = explode("/yts/jsbin/player", $playerURL)[1];
				$playerID = explode("-", explode("/", $playerID)[0]);
				$playerID = $playerID[count($playerID)-1];
			} catch(\Exception $e){
				$this->error = "Failed to parse playerID from player url: ".$playerURL;
				return false;
			}

			$playerURL = str_replace('\/', '/', explode('"', $playerURL)[0]);
			if(!file_exists($this->settings['temporaryDirectory'].$playerID.'.js')){
				$decipherScript = \ScarletsFiction\WebApi::loadURL("http://www.youtube.com$playerURL");
				file_put_contents($this->settings['temporaryDirectory'].$playerID.'.js', $decipherScript);
			}

			$this->data['playerID'] = $playerID;
			return $playerID;
		}

		private function getSignatureParser(){
			$this->data['signature'] = ['playerID'=>$this->data['playerID']];
			if($this->settings['signatureDebug']){
				$this->data['signature']['log'] = "==== Load player script and execute patterns ====\n\n";
				$this->data['signature']['log'] .= "Loading player ID = ".$this->data['playerID']."\n";
			}
			
			if(!$this->data['playerID']) return false;

			if(file_exists($this->settings['temporaryDirectory'].$this->data['playerID'].'.js')) {
				$decipherScript = file_get_contents($this->settings['temporaryDirectory'].$this->data['playerID'].'.js');
			} else{
				$this->error = "Player script was not found for id: ".$this->data['playerID'];
				if($this->settings['signatureDebug'])
					
				return false;
			}
		
			// Some preparation
			$signatureCall = explode('("signature",', $decipherScript);
			$callCount = count($signatureCall);
			if($callCount<=0){
				$this->error = "Failed to get signature function";
				return false;
			}

			// Search for function call for example: e.set("signature",PE(f.s));
			// We need to get "PE"
			$signatureFunction = "";
			for($i=$callCount-1; $i > 0; $i--){
				$signatureCall[$i] = explode(');', $signatureCall[$i])[0];
				if(strpos($signatureCall[$i], '(')){
					$signatureFunction = explode('(', $signatureCall[$i])[0];
					break;
				}
			}
			
			if($this->settings['signatureDebug'])
				$this->data['signature']['log'] .= 'signatureFunction = '.$signatureFunction."\n";

			$decipherPatterns = explode($signatureFunction."=function(", $decipherScript)[1];
			$decipherPatterns = explode('};', $decipherPatterns)[0];
			
			if($this->settings['signatureDebug'])
				$this->data['signature']['log'] .= 'decipherPatterns = '.$decipherPatterns."\n";
		
			$deciphers = explode("(a", $decipherPatterns);
			for ($i=0; $i < count($deciphers); $i++) { 
				$deciphers[$i] = explode('.', explode(';', $deciphers[$i])[1])[0];
				if(count(explode($deciphers[$i], $decipherPatterns))>=2){
					// This object was most called, that's mean this is the deciphers
					$deciphers = $deciphers[$i];
					break;
				}
				else if($i==count($deciphers)-1){
					$this->error = "Failed to get deciphers function";
					return false;
				}
			}
		
			$deciphersObjectVar = $deciphers;
			$decipher = explode($deciphers.'={', $decipherScript)[1];
			$decipher = str_replace(["\n", "\r"], "", $decipher);
			$decipher = explode('}};', $decipher)[0];
			$decipher = explode("},", $decipher);
			if($this->settings['signatureDebug'])
				$this->data['signature']['log'] .= print_r($decipher, true);
		
			// Convert pattern to array
			$decipherPatterns = str_replace($deciphersObjectVar.'.', '', $decipherPatterns);
			$decipherPatterns = str_replace('(a,', '->(', $decipherPatterns);
			$decipherPatterns = explode(';', explode('){', $decipherPatterns)[1]);
			$this->classData['signature']['patterns'] = $decipherPatterns;
		
			// Convert deciphers to object
			$deciphers = [];
			foreach ($decipher as &$function) {
				$deciphers[explode(':function', $function)[0]] = explode('){', $function)[1];
			}
			$this->classData['signature']['deciphers'] = $deciphers;

			// Save decipher to file
			if(isset($this->classData['signature']['log']))
				unset($this->classData['signature']['log']);
			file_put_contents($this->settings['temporaryDirectory'].'decipherData.inf', json_encode($this->classData['signature']));

			return true;
		}
		
		private function decipherSignature($signature, $forceReset=false){
			$this->getLatestPlayerScript($forceReset);

			if(isset($this->data['signature']['playerID'])&&$this->data['signature']['playerID']==$this->data['playerID']){
				if($this->settings['signatureDebug'])
					$this->data['signature']['log'] = "==== Deciphers loaded ====\n";
			}

			if(!isset($this->classData['signature']['patterns'])){
				$this->error = "Signature patterns not found";
				return false;
			}
			$patterns = $this->classData['signature']['patterns'];
			$deciphers = $this->classData['signature']['deciphers'];

			if($this->settings['signatureDebug']){
				$this->data['signature']['log'] = "==== Retrieved deciphers ====\n\n";
				$this->data['signature']['log'] .= print_r($patterns, true);
				$this->data['signature']['log'] .= print_r($deciphers, true);
			}
		
			if($this->settings['signatureDebug'])
				$this->data['signature']['log'] .= "\n\n\n==== Processing ====\n\n";
		
			// Execute every $patterns with $deciphers dictionary
			$processSignature = $signature;
			for ($i=0; $i < count($patterns); $i++) {
				// This is the deciphers dictionary, and should be updated if there are different pattern
				// as PHP can't execute javascript
		
				//Handle non deciphers pattern
				if(strpos($patterns[$i], '->')===false){
					if(strpos($patterns[$i], '.split("")')!==false)
					{
						$processSignature = str_split($processSignature);
						if($this->settings['signatureDebug'])
							$this->data['signature']['log'] .= "String splitted\n";
					}
					else if(strpos($patterns[$i], '.join("")')!==false)
					{
						$processSignature = implode('', $processSignature);
						if($this->settings['signatureDebug'])
							$this->data['signature']['log'] .= "String combined\n";
					}
					else{
						$this->error = "Decipher dictionary was not found #1";
						return false;
					}
				} 
				else
				{
					//Separate commands
					$executes = explode('->', $patterns[$i]);
		
					// This is parameter b value for 'function(a,b){}'
					$number = intval(str_replace(['(', ')'], '', $executes[1]));
					// Parameter a = $processSignature
		
					$execute = $deciphers[$executes[0]];
		
					//Find matched command dictionary
					if($this->settings['signatureDebug'])
						$this->data['signature']['log'] .= "Executing $executes[0] -> $number";
					switch($execute){
						case "a.reverse()":
							$processSignature = array_reverse($processSignature);
							if($this->settings['signatureDebug'])
								$this->data['signature']['log'] .= " (Reversing array)\n";
						break;
						case "var c=a[0];a[0]=a[b%a.length];a[b]=c":
							$c = $processSignature[0];
							$processSignature[0] = $processSignature[$number%count($processSignature)];
							$processSignature[$number] = $c;
							if($this->settings['signatureDebug'])
								$this->data['signature']['log'] .= " (Swapping array)\n";
						break;
						case "a.splice(0,b)":
							$processSignature = array_slice($processSignature, $number);
							if($this->settings['signatureDebug'])
								$this->data['signature']['log'] .= " (Removing array)\n";
						break;
						default:
							$this->error = "Decipher dictionary was not found #2";
							return false;
						break;
					}
				}
			}
		
			if($this->settings['signatureDebug']){
				$this->data['signature']['log'] .= "\n\n\n==== Result ====\n";
				$this->data['signature']['log'] .= "Signature  : ".$signature."\n";
				$this->data['signature']['log'] .= "Deciphered : ".$processSignature;
			}

			return $processSignature;
		}
	}

	class Channel extends LittleYoutubeInfo
	{
		public function init($url)
		{
			$id = $url;
			if(strpos($id, '/user/')!==false){
				$id = explode('/user/', $id)[1];
				$id = explode('/', $id)[0];
				$id = explode('?', $id)[0];
				$this->data['userID'] = explode('?', $id)[0];
			}
			else if(strpos($id, 'channel_id=')!==false){
				$id = explode('channel_id=', $id)[1];
				$this->data['channelID'] = explode('&', $id)[0];
			}
			else if(strpos($id, '/channel/')!==false){
				$id = explode('/channel/', $id)[1];
				$this->data['channelID'] = explode('&', $id)[0];
			} else $this->data['channelID'] = $id;
			if($this->settings["processDetail"])
				$this->processDetails();
		}

		public function processDetails(){
			$data = [];
			if(isset($this->data['channelID'])){
				$data[0] = "https://www.youtube.com/channel/".$this->data['channelID']."/playlists";
				$data[1] = "https://www.youtube.com/channel/".$this->data['channelID']."/videos?sort=dd&view=0&shelf_id=2";
			} else if(isset($this->data['userID'])){
				$data[0] = "https://www.youtube.com/user/".$this->data['userID']."/playlists";
				$data[1] = "https://www.youtube.com/user/".$this->data['userID']."/videos?sort=dd&view=0&shelf_id=2";
			} else {
				$this->error = "No Channel ID found";
				return false;
			}

			// Playlists
			$value = \ScarletsFiction\WebApi::loadURL($data[0])['content'];
			$value = explode('/playlist?list=', $value);
			if(count($value)==1){
				$value = explode('ytInitialData"] = ', $value[0])[1];
				$value = explode('};', $value)[0].'}';
				$value = json_decode($value, true);
				$user = $value['header']['c4TabbedHeaderRenderer'];
				$this->data['channelID'] = $user['channelId'];				$this->data['userData'] = [
					"name"=>$user['title'],
					"image"=>$user['avatar']['thumbnails'][0]['url']
				];

				$value = $value['contents']['twoColumnBrowseResultsRenderer']['tabs'][2]['tabRenderer']['content']['sectionListRenderer']['contents'][0]['itemSectionRenderer']['contents'][0]['gridRenderer'];

				foreach ($value['items'] as $value_){
					$values = $value_['gridPlaylistRenderer'];
					$this->data['playlists'][] = ["title"=>$values['title']['simpleText'], "playlistID"=>$values['playlistId']];
				}
				$this->data['videos'] = [];
				return;
			}

			$this->data['channelID'] = explode('/', explode('?', explode('"', explode('/channel/', $value[0])[1])[0])[0])[0];
			$this->data['userID'] = explode('/', explode('?', explode('"', explode('/user/', $value[0])[1])[0])[0])[0];//src="

			$userData = explode('>', explode('appbar-nav-avatar', $value[0])[1])[0];
			$this->data['userData'] = [
				"name"=>explode('"', explode('title="', $userData)[1])[0],
				"image"=>explode('"', explode('src="', $userData)[1])[0],
			];

			unset($value[0]); $value = array_values($value);
			foreach ($value as &$value_) {
				$value_ = explode('</a>', $value_)[0];
				$value_ = explode('>', $value_);
				$value_ = ["title"=>$value_[1], "playlistID"=>explode('"', $value_[0])[0]];
			}
			$this->data['playlists'] = $value;

			// Videos
			$value = \ScarletsFiction\WebApi::loadURL($data[1])['content'];
			$value = explode('yt-lockup-title', $value);
			unset($value[0]); $value = array_values($value);

			foreach ($value as &$value_) {
				$value_ = explode('</span>', $value_)[0];
				$value_ = explode('/watch?v=', $value_)[1];
				$value_ = explode('>', $value_);
				$value_ = [
					"title"=>explode('<', $value_[1])[0],
					"duration"=>explode('Duration: ', $value_[count($value_)-1])[1],
					"videoID"=>explode('"', $value_[0])[0]];
			}
			$this->data['videos'] = $value;
		}

		public function getChannelRSS($load=false, $parse=false){
			$link = "https://www.youtube.com/feeds/videos.xml?channel_id=".$this->data['channelID'];
			if(!$load) return $link;
			else{
				$data = \ScarletsFiction\WebApi::loadURL($link);
				if($parse){
					//ToDo: build youtube rss parser
				} else return $data;
			}
		}
	}

	class Playlist extends LittleYoutubeInfo
	{
		public function init($url)
		{
			$id = $url;
			if(strpos($id, 'list=')!==false){
				$id = explode('list=', $id)[1];
				$this->data['playlistID'] = explode('&', $id)[0];
			}
			else $this->data['playlistID'] = $id;
			if($this->settings["processDetail"])
				$this->processDetails();
		}

		public function processDetails(){
			$data = \ScarletsFiction\WebApi::loadURL('https://www.youtube.com/playlist?list='.$this->data['playlistID'])['content'];
			if(strpos($data, 'Sorry for the interruption')!==false){
				$this->error = "Need to solve captcha from youtube";
				return false;
			}
			$data = explode('data-title="', $data);
			if(count($data)==1){
				$data = explode('ytInitialData"] = ', $data[0])[1];
				$data = explode('};', $data)[0].'}';
				$data = json_decode($data, true);

				if(!isset($data['sidebar'])){
					$this->error = "This feature can't be used for a playlist created by Youtube";
					return;
				}
				$user = $data['sidebar']['playlistSidebarRenderer']['items'][1]['playlistSidebarSecondaryInfoRenderer']['videoOwner']['videoOwnerRenderer'];
				$this->data['userData'] = [
					"name"=>$user['title']['runs'][0]['text'],
					"image"=>$user['thumbnail']['thumbnails'][0]['url']
				];

				$data = $data['contents']['twoColumnBrowseResultsRenderer']['tabs'][0]['tabRenderer']['content']['sectionListRenderer']['contents'][0]['itemSectionRenderer']['contents'][0]['playlistVideoListRenderer'];

				$playlists = [];
				foreach ($data['contents'] as $value_){
					$values = $value_['playlistVideoRenderer'];
					$this->data['videos'][] = ["title"=>$values['title']['accessibility']['accessibilityData']['label'], "videoID"=>$values['videoId']];
				}
				return;
			}

			$this->data['channelID'] = explode('/', explode('?', explode('"', explode('/channel/', $data[0])[1])[0])[0])[0];
			$this->data['userID'] = explode('/', explode('?', explode('"', explode('/user/', $data[0])[1])[0])[0])[0];
			$userData = explode('>', explode('appbar-nav-avatar', $data[0])[1])[0];
			$this->data['userData'] = [
				"name"=>explode('"', explode('title="', $userData)[1])[0],
				"image"=>explode('"', explode('src="', $userData)[1])[0],
			];

			unset($data[0]); $data = array_values($data);
			foreach ($data as &$value){
				$title = explode('" ', $value)[0];
				$playlistID = explode('watch?v=', $value);
				$playlistID = explode('&amp;', $playlistID[1])[0];
				$value = ["title"=>$title, "videoID"=>$playlistID];
			}
			$this->data['videos'] = $data;
		}
	}

	class Search extends LittleYoutubeInfo
	{
		public function init($query)
		{
			$this->data['query'] = $query;
			if($this->settings["processDetail"])
				$this->processDetails();
		}

		public function processDetails(){
			$url = "https://www.youtube.com";
			if(isset($this->data['queryNext']))
				$url .= $this->data['next'];
			else if(isset($this->data['queryPrevious']))
				$url .= $this->data['previous'];
			else
				$url .= '/results?search_query='.urlencode($this->data['query']);
			$this->data['videos'] = [];

			$data = \ScarletsFiction\WebApi::loadURL($url)['content'];
			if(strpos($data, 'Sorry for the interruption')!==false){
				$this->error = "Need to solve captcha from youtube";
				return false;
			}
				print_r($data);exit;
			$data = explode('yt-lockup-title', $data);
			if(count($data)==1){
				$data = explode('ytInitialData"] = ', $data[0])[1];
				$data = explode('};', $data)[0].'}';
				$data = json_decode($data, true);
				$data = $data['contents']['twoColumnSearchResultsRenderer']['primaryContents']['sectionListRenderer']['contents'][0]['itemSectionRenderer'];

				foreach ($data['contents'] as $value){
					if(!isset($value['videoRenderer'])) continue;
					$dat = $value['videoRenderer'];
					$videoID = $dat['videoId'];
					$title = $dat['title']['simpleText'];
					$duration = $dat['lengthText']['simpleText'];
					$userName = $dat['ownerText']['runs'][0]['text'];
					$views = $dat['viewCountText']['simpleText'];
					$uploaded = $dat['publishedTimeText'];
					$this->data['videos'][] = ['videoID'=>$videoID, 'title'=>$title, 'duration'=>$duration, 'user'=>$userName, 'uploaded'=>$uploaded, 'views'=>$views];
				}
				return;
			}
			unset($data[0]); $data = array_values($data);
			$dataCount = count($data);

			unset($this->data['next']);
			unset($this->data['previous']);
			for($i = 0; $i<$dataCount; $i++){
				if($i==$dataCount-1){
					if(strpos($data[$i], '>Next')){
						$next = explode("<a", explode(">Next", $data[$i])[0]);
						$next = $next[count($next)-1];
						$next = explode('"', explode("/results?q=", $next)[0])[1];
						$next = html_entity_decode($next);
						$this->data['next'] = $next;
					}
					else if(strpos($data[$i], 'Previous<')){
						$prev = explode("<a", explode("Previous<", $data[$i])[0]);
						$prev = $prev[count($prev)-1];
						$prev = explode('"', explode("/results?q=", $prev)[0])[1];
						$prev = html_entity_decode($prev);
						$this->data['previous'] = $prev;
					}
				}
				if(strpos($data[$i], '/playlist?list=')!==false){
					continue;
				}
				$videoID = explode('/watch?v=', $data[$i]);
				if(count($videoID)==1) continue; //Not a video
				if(strpos($videoID[1], 'list=')!==false) continue; //It's playlist
				$videoID = explode('"', $videoID[1])[0];

				$title = explode('title="', $data[$i])[1];
				$title = explode('"', $title)[0];

				$duration = explode('Duration: ', $data[$i]);
				if(count($duration)==1){
					$duration = explode('video-time', $data[$i])[1];
					$duration = explode('<', $duration)[0];
					$duration = explode('>', $duration)[1];
				}
				else {
					$duration = $duration[1];
					$duration = explode('.</span>', $duration)[0];
				}

				$user = explode('/user/', $data[$i]);
				if(count($user)==1)
					$user = explode('/channel/', $data[$i]);
				if(count($user)!=1){
					$user = explode('</a>', $user[1])[0];
					$userID = explode('"', $user)[0];
					$userName = explode('>', $user)[1];
					$userName = explode('<span', $userName)[0];
				} else {
					$userID = "";
					$userName = "";
				}

				$meta = explode('yt-lockup-meta-info', $data[$i])[1];
				$meta = explode('</ul>', $meta)[0];
				$meta = explode('<li>', $meta);
				if(count($meta)==3)
					$views = explode('</li>', $meta[2])[0];
				$uploaded = explode('</li>', $meta[1])[0];

				$this->data['videos'][] = ['videoID'=>$videoID, 'title'=>$title, 'duration'=>$duration, 'userID'=>$userID, 'user'=>$userName, 'uploaded'=>$uploaded, 'views'=>$views];
			}
		}

		public function next(){
			$this->data['queryNext'] = 1;
			$this->processDetails();
			unset($this->data['queryNext']);
		}

		public function previous(){
			$this->data['queryPrevious'] = 1;
			$this->processDetails();
			unset($this->data['queryPrevious']);
		}
	}
}

namespace ScarletsFiction{
	class LittleYoutube{
		public static function video($id, $options=false){
			return new LittleYoutube\Video($id, $options);
		}
		public static function channel($id, $options=false){
			return new LittleYoutube\Channel($id, $options);
		}
		public static function playlist($id, $options=false){
			return new LittleYoutube\Playlist($id, $options);
		}
		public static function search($id, $options=false){
			return new LittleYoutube\Search($id, $options);
		}
	}

	class WebApi
	{
		public static function loadURL($url, $options=false){
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.84 Safari/537.36');
		
			$headers = [];
			$headers[] = 'Accept: */*;q=0.8';
			$headers[] = 'Accept-Language: en-US,en;q=0.5';
			$headers[] = 'Connection: keep-alive';

			if($options){
				if(isset($options['headerOnly'])){
					curl_setopt($ch, CURLOPT_NOBODY, true);
				}
				if(isset($options['headers'])){
					$headers = $options['headers'];
				}
				if(isset($options['cookies'])){
					curl_setopt($ch, CURLOPT_COOKIE, $options['cookies']);
				}
			}
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_ENCODING, "gzip");
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_HEADER, 1);
			curl_setopt($ch, CURLINFO_HEADER_OUT, 1);
			$data = curl_exec($ch);
			
			$header  = curl_getinfo($ch);
			if($header['http_code']!=200){
				curl_close($ch);
				echo("Connection error: ".$header['http_code']);
				return ['headers'=>'', 'content'=>'', 'cookies'=>''];
			}
			$myHeader = $header['request_header'];
			curl_close( $ch );
		
			$header_content = substr($data, 0, $header['header_size']);
			$body_content = trim(str_replace($header_content, '', $data));
			$pattern = "#Set-Cookie:\\s+(?<cookie>[^=]+=[^;]+)#m"; 
			preg_match_all($pattern, $header_content, $matches); 
			$cookies = implode("; ", $matches['cookie']);
			
			$data = ['headers'=>$header_content, 'content'=>$body_content, 'cookies'=>$cookies];
			return $data;
		}
		public static function urlContentSize($url){
			$data = self::loadURL($url, ['headerOnly'=>true]);
			$size = 0;
			if($data['headers']) {
				$data['headers'] = explode(" 200 OK", $data['headers'])[1];
				if(preg_match("/Content-Length: (\d+)/", $data['headers'], $matches)){
		    	  $size = (int)$matches[1];
		    	}
		    }
			return $size;
		}
	}

	class FileApi{
		public static function fileSize($bytes, $decimals=2) {
		  	$sz = 'BKMGTP';
		  	$factor = floor((strlen($bytes) - 1) / 3);
		  	return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)).' '.@$sz[$factor].($factor!=0?'B':'ytes');
		}
	}

	class Stream{
		//ToDo - paralel download

		//type = "application/octet-stream"
		public static function localFile($name, $path, $type=false, $downloadSpeed=1024){
			$size = filesize($path);
			$fopen = fopen($path, 'r+');
			if(!$fopen) return false;

			header("Accept-Ranges: bytes");
			if(isset($_SERVER['HTTP_RANGE'])){
			    $ranges = array_map('intval', explode('-', substr($_SERVER['HTTP_RANGE'], 6)));
			    if(!$ranges[1]) $ranges[1] = $size - 1;
			 
			    header('HTTP/1.1 206 Partial Content');
			    header('Content-Length: '.($ranges[1] - $ranges[0]));
			    header('Content-Range: bytes $ranges[0]-$ranges[1]/$size');

				fseek($fioh, $ranges[0]);
			}else{
				fseek($fioh, 0);
			}
			
			self::buildResponse($name, $type, $size);

			while(!feof($fioh)) {
			    print(fread($fioh, $downloadSpeed/4));
			    flush();
			    @ob_flush();
			}
			fclose($fioh);
		}

		public static function variableFile($name, &$string, $type=false, $downloadSpeed=1024){
			$size = strlen($string);
			if(!$size) return false;
			self::buildResponse($name, $type, $size);
			print_r($string);
			exit;
		}

		private static function buildResponse($name, $type, $size){
			ob_get_clean();
			if(!$type) $type = "application/octet-stream";
			header('Content-type: '.$type);
			header('Content-Disposition: attachment; filename="'.$name.'"');
			header('Content-Length: '.$size);
		}
	}
}