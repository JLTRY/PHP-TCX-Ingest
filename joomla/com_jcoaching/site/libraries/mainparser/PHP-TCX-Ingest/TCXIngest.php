<?php
/** TCXIngest Class
*
* Class to Ingest a basic GPX file, generate stats, convert it into an object and allow some basic manipulation.
* After ingest, the object can be output as JSON for easy storage with stats intact.
*
* @copyright (C) 2013 B Tasker (http://www.bentasker.co.uk). All rights reserved
* @license GNU GPL V2 - See LICENSE
*
* @version 1.2
*
* Where issue keys are included (GPXIN-[0-9]+), the relevant issue can be viewed at http://projects.bentasker.co.uk/jira_projects/browse/GPXIN.html
*/

$LOGFILE=dirname(__FILE__)."/log.txt";
if (file_exists($LOGFILE)){
	unlink($LOGFILE);
}


	/** Builds an object structure to be used to store the outer bounds for longitude and latitude
	*
	* @return stdclass
	*/
class buildBoundsObj {
	function __construct() {	
		$this->Lat = new \stdClass();
		$this->Lat->min = 0;
		$this->Lat->max = 0;
		$this->Lon = new \stdClass();
		$this->Lon->min = 0;
		$this->Lon->max = 0;
	}
}

class TCXPoint {
	public $extensions;
	public $lat;
	public $lon;
	public $time;
	public $elevation;
	public $speed;
	public $power;
	function __construct(){
		$this->extensions = new \stdClass();
	}
	public function __sleep()
    {
		return array("extensions", "lat", "lon", "time", "elevation", "heartratebpm","speed", "power");
	}
}


class TCXSegment {
	public $points;
	public $stats;
	function __construct(){
		$this->points = array();
		$this->stats = new \stdClass();
	}
	public function __sleep()
    {
		return array("points");
	}
	
	function addPoint()
	{		
		$pt = new TCXPoint();
		$this->points[] = $pt;
		return $pt;
	}
}



class TCXLap {
	public $segments;
	function __construct(){
		$this->segments = array();
	}
	
	public function __sleep()
    {
		return array("segments");
	}
	function addSegment()
	{
		$i = count($this->segments);
		$seg = new TCXSegment();
		$this->segments[] = $seg;
		return $seg;
	}
}

class TCXActivityStats {
	public $journeyDuration;
	public $maxacceleration;
	public $maxdeceleration;
	public $minacceleration;
	public $mindeceleration;
	public $avgacceleration;
	public $avgdeceleration;
	public $speedUoM;
	public $timeMoving;
	public $timeStationary;
	public $timeAccelerating;
	public $timeDecelerating;
	public $distanceTravelled;
	public $bounds; 
	public $summary; 
	public $laps;
	public $start;
	public $end;
	
	function __construct($trk){
		$this->summary = (string)$trk->attributes()['Sport'];
		$this ->bounds = new BuildBoundsObj();
		$this->laps = array();
		$this->speedUoM = array();
		$this->journeyDuration = 0;
		$this->maxacceleration = 0;
		$this->maxdeceleration = 0;
		$this->minacceleration = 0;
		$this->mindeceleration = 0;
		$this->avgacceleration = 0;
		$this->avgdeceleration = 0;
		$this->timeMoving = 0;
		$this->timeStationary = 0;
		$this->timeAccelerating = 0;
		$this->timeDecelerating = 0;
		$this->distanceTravelled = 0;
	}
	
	public function __sleep()
    {
		return array("summary", 
			"laps",
			"start",
			"end",
			"totalspeed",
			"totaltime",
			"totaldistance"
		);
	}
	
	function addLap($lap) {
		array_push($this->laps, (object)array('TotalTimeSeconds' => (string)$lap->TotalTimeSeconds,
									'DistanceMeters' => (string)$lap->DistanceMeters,
									'Speed' => ($lap->TotalTimeSeconds==0)?0 : (3600*$lap->DistanceMeters)/(int)$lap->TotalTimeSeconds));	
	
	}
}
				
//Activity //Trck
class TCXActivity {
	public $name;
	public $jkey;
	public $stats;
	public $laps;
	public $segments;
	
	function __construct($jkey, $trk){		
		$this->$name = $trk->name;
		$this->jkey = $jkey;
		$this->segments = new \stdClass();
		$this->laps = array();
		$this->stats = new  TCXActivityStats($trk);
	}
	
	public function __sleep()
    {
		return array("name", 
			"stats",
			"laps",
		);
	}
	
	public function addLap($lap) {
		$this->stats->addLap($lap);
		$curlap = new TCXLap();
		array_push($this->laps, $curlap);
		return $curlap;
	}
}


class TCXIngestActivity extends TCXActivity {
}

class TCXIngest {
	
	protected $file;
	protected $xml;
	protected $journey;
	protected $tracks = array();
	protected $highspeeds;
	protected $lowspeeds;
	protected $journeyspeeds;
	protected $totaltimes;
	protected $ftimes;
	protected $trackduration;
	protected $smarttrack=true;
	protected $smarttrackthreshold = 3600;
	protected $suppresscalcdistance = false;
	protected $suppresslocation = false;
	protected $suppressspeed = false;
	protected $suppresselevation = false;
	protected $suppressdate = false;
	protected $suppresswptlocation = false;
	protected $suppresswptele = false;	
	protected $lastspeed = false;
	protected $lastspeedm = false;
	protected $lastrteele = false;
	protected $rteeledev = array();
	protected $journeylats;
	protected $journeylons;
	protected $segmentlats;
	protected $segmentlons;
	protected $tracklats;
	protected $tracklons;
	protected $ingest_version = 1.03;
	protected $entryperiod;
	protected $experimentalFeatures = array('calcElevationGain'); // See GPXIN-17
	protected $featuretoggle = array();
	protected $waypoints;


	/** Standard constructor
	*
	*/
	function __construct(){
		$this->journey = new \stdClass();
		$this->journey->related = new \stdClass();
		$this->journey->related->waypoints = new \stdClass();
		$this->journey->related->waypoints->points = array();
		$this->journey->related->routes = new \stdClass();
		$this->journey->journeys = array();
	}


	/** Reset all relevant class variables - saves unloading the class if processing multiple files
	*
	*/
	public function reset(){
		$this->journey = new \stdClass();
	}


	/** Load XML from a file
	*
	* @arg $file - string to XML file, can be relative or full path
	*
	*/
	public function loadFile($file){
		$this->xml = simplexml_load_file($file, "SimpleXMLElement");//, 0, "https://www8.garmin.com/xmlschemas/ActivityExtensionv2.xsd", TRUE);
		if ($this->xml){
			return true;
		}else{
			$this->xml = false;
			return false;
		}
	}


	/** Load an XML string
	*
	* @arg $str - XML String
	*
	*/
	public function loadString($str){
		$this->xml = simplexml_load_string($str);
		if ($this->xml){
			return true;
		}else{
			$this->xml = false;
			return false;
		}
	}



	/** Toggle SmartTrack on/off
	*
	*/
	public function toggleSmartTrack(){
		$this->smarttrack = ($this->smarttrack)? false : true;
		return $this->smarttrack;
	}



	/** Get the current Smart Track Status
	*
	*/
	public function smartTrackStatus(){
		return $this->smarttrack;
	}


	/** Get the smartTrackThreshold
	*
	*/
	public function smartTrackThreshold(){
		return $this->smarttrackthreshold;
	}



	/** Set the smart Track trigger threshold
	*
	*/
	public function setSmartTrackThreshold($thresh){
		$this->smarttrackthreshold = $thresh;
	}





	/** Ingest the XML and convert into an object
	*
	* Also updates our reference arrays
	*
	*/
	public function ingest(){
		
		if (!is_object($this->xml)){
			return false;
		}

		// Initialise the object
		$this->journey->created = new \stdClass();
		$this->journey->stats = new \stdClass();
		$this->journey->stats->speedUoM = array();
		
		$zeroed_stats = array ('trackpoints','recordedDuration','segments','tracks',
								'maxacceleration','maxdeceleration','mindeceleration',
								'avgacceleration','avgdeceleration','timeMoving','timeStationary',
								'timeAccelerating','timeDecelerating','distanceTravelled');
		
		foreach ($zeroed_stats as $k){
			$this->journey->stats->$k = 0;
		}
		
		// Bounds introduced in GPXIN-26
		$this->journey->stats->bounds = new buildBoundsObj();
		
		// GPXIN-33 Create route related stats object
		$this->journey->stats->routestats = new \stdClass();
		$this->journey->stats->routestats->bounds = new buildBoundsObj();                
                
		// Initialise the stats array
		$this->totaltimes = array();
		$this->highspeeds = array();
		$this->journeyspeeds = array();
		$this->lowspeeds = array();
		$this->accels = array();
		$this->decels = array();
		$this->jeles = array();
		$this->jeledevs = array();
		$this->jdist = array(); //GPXIN-6
        	$this->journeylats = array(); //GPXIN-26
        	$this->journeylons = array(); //GPXIN-26
		$unit = null;
		

		// Add the metadata
		$this->journey->created->creator = (string) $this->xml['creator'];
		$this->journey->created->version = (string) $this->xml['version'];
		$this->journey->created->format = 'TCX';
		$this->journey->created->namespaces = $this->xml->getNamespaces(true);

		if (!$this->suppressdate && isset($this->xml->time)){
			$this->journey->created->time = strtotime($this->xml->time);
		}

		$this->journey->timezone = date_default_timezone_get();

		// Create the GPXIngest Metadata object
		$this->journey->metadata = new \stdClass();
	     $this->journey->metadata->AutoCalc = array('speed'=>false);
		$this->journey->metadata->waypoints = 0; //GPXIN-24
		$this->journey->metadata->routes = 0;
		

		// There may be multiple tracks in one file
		foreach ($this->xml->Activities->Activity as $trk){			
			// Initialise the stats variables
			$this->resetTrackStats();
			$b = 0;	
			$activity = $this->addActivity($trk);			
			foreach ($trk->Lap as $lap){				
				//specific for tcx
				$curlap = $activity->addLap($lap);
				// There may be multiple segments if GPS connectivity was lost - process each seperately
				foreach ($lap->Track as $trkseg){
					// Initialise the sub-stats variable
					$this->resetSegmentStats();
					$x = 0;
					$times = array();
					$lastele = false;
					$timemoving = 0;
					$timestationary = 0;

					// Initialise the segment object
					$this->initSegment();
					$segment = $curlap->addSegment();
					// Trackpoint details in trk - Push them into our object
					foreach ($trkseg->Trackpoint as $trkpt){					
						$time = strtotime((string)$trkpt->Time);				
						$point = $segment->addPoint();
						// Handle Extensions (GPXIN-20)
						if (isset($trkpt->Extensions)){
							foreach ($this->journey->created->namespaces as $ns=>$nsuri){
								if (empty($ns)){
									continue;
								}
								$ext = array();
								foreach ($trkpt->Extensions->children($nsuri) as $t){
								  if (!count($t->children($nsuri))) {
									  $ext[$t->getName()] = (string)$t;
								  } else 
								  foreach ($t->children($nsuri) as $t1){
									  $ext[$t1->getName()] = (string)$t1;
								  }
								}
								if (count($ext)) {
									$point->extensions->$ns = $ext;
								}
							  }
						}
						if (property_exists($trkpt, 'HeartRateBpm') )
						{
							$point->heartratebpm = ((string)$trkpt->HeartRateBpm->children()[0])/1;
						}
						if (property_exists($point, 'extensions') && 
							property_exists($point->extensions, 'ns3') && 
							array_key_exists('Watts', $point->extensions->ns3)) {
							$point->power = $point->extensions->ns3['Watts']/1;
						}
						// Calculate the period to which this trackpoint relates
						if ($this->lasttimestamp){
							$this->entryperiod = $time - $this->lasttimestamp;
						}
						// Write the track data - take into account whether we've suppressed any data elements
						if (!$this->suppresslocation){
							$position =  $trkpt->Position;	
							$lat = $position->LatitudeDegrees; // let's only caste once
							$lon = $position->LongitudeDegrees;

							$point->lat = (string)$lat;
							$point->lon = (string)$lon;

							/** Implemented in GPXIN-6 - currently experimental so will generally be 0 */
							$dist = ($this->lastpos)? (string)$trkpt->DistanceMeters - $this->lastpos : 0;
							//TCXIngest::my_log(sprintf("%f",$dist));		
							$this->lastpos = (string)$trkpt->DistanceMeters;
							// Update the stats arrays
							$this->fdist[] = $dist;
							$this->sdist[] = $dist;
							$this->jdist[] = $dist;
						}
						if (!$this->suppresselevation){
							$ele = (string) $trkpt->AltitudeMeters;
							// This is going to be deprecated (GPXIN-34)
							$point->elevation = $ele;
							$change = 0;
							if ($lastele){
								$change = $ele - $lastele;
							}
							$point->elevationChange = $change;						
							// Update the stats arrays - should be able to make this more efficient later
							$this->jeles[] = $ele;
							$this->seles[] = $ele;
							$this->feles[] = $ele;
							$this->jeledevs[] = $change;
							$this->seledevs[] = $change;
							$this->feledevs[] = $change;

							// Update the elevation for the next time round the block	
							$lastele = $ele;
						}
						if (!$this->suppressdate){
							$point->time = $time;
							// Update the times arrays
							$times[] = $time;
							// update lasttime - used by SmartTrack
							$lasttime = $time;
						}
						if (!$this->suppressspeed){

							// What is the speed recorded in?
							//$unit = strtolower(substr(rtrim($speed_string),strlen($speed_string)-3));
							//ns3 contains speed
							$ret1 = property_exists($point, 'extensions');
							$ret2 = property_exists($point->extensions, 'ns3');
							$ret3 = array_key_exists('Speed', $point->extensions->ns3);
							if (property_exists($point, 'extensions') && 
								property_exists($point->extensions, 'ns3') && 
								array_key_exists('Speed', $point->extensions->ns3)) {
								$point->speed = $point->extensions->ns3['Speed']/1;
							}
							//$point->speedint = $ptspeed;
							// Calculate speed stats
							//$this->speed = $this->speed + $ptspeed;
							$this->fspeed[] = $ptspeed;
							$this->sspeed[] = $ptspeed;

							// Calculate acceleration
							list($acc,$decc) = $this->calculateAcceleration($point->speed, $time,'kph');
							$point->acceleration = $acc;
							$point->deceleration = $decc;

							// There shouldn't usually be more than one UoM per track file, but you never know - that's why it's an array
							if (!in_array($unit,$this->journey->stats->speedUoM)){
								$this->journey->stats->speedUoM[] = $unit;
							}

							// Tracks may also, plausibly, contain more than one measurement
							if (!in_array($unit,$activity->stats->speedUoM)){
								$activity->stats->speedUoM[] = $unit;
							}
							// If there's more than one unit per segment on the other hand, something's wrong!						

						}else{
							// We also use the speed array to identify the number of trackpoints
							$this->fspeed[] = 1;
							$unit = null;
						}
						// Set our values for the next run
						$this->lasttimestamp = $time;
						$this->lastspeed = $ptspeed;
						// Up the counters
						$x++;
					}
				$this->writeSegmentStats($activity, $segment,$times,$x,$unit,$timemoving,$timestationary);	
				$b++;
			}
				$this->writeTrackStats($activity);
				$trackcounter++; # Increment the track counter
			}
		}
		$modesearch = array_count_values($this->journeyspeeds);
		// Finalise the object stats - again take suppression into account
		if (!$this->suppressdate && sizeof($this->totaltimes)) {
			$this->journey->stats->start = min($this->totaltimes);
			$this->journey->stats->end = max($this->totaltimes);
		}
		if (!$this->suppressspeed && sizeof($this->highspeeds)){
			$this->journey->stats->maxSpeed = max($this->highspeeds);
			$this->journey->stats->minSpeed = min($this->lowspeeds);
			$this->journey->stats->modalSpeed = array_search(max($modesearch),$modesearch);
			$this->journey->stats->avgspeed = round(array_sum($this->journeyspeeds) / $this->journey->stats->trackpoints,2);
			$this->journey->stats->maxacceleration = max($this->accels);
			$this->journey->stats->maxdeceleration = max($this->decels);
			$this->journey->stats->minacceleration = min($this->accels);
			$this->journey->stats->mindeceleration = min($this->decels);
			$this->journey->stats->avgacceleration = round(array_sum($this->accels)/count($this->accels),2);
			$this->journey->stats->avgdeceleration = round(array_sum($this->decels)/count($this->accels),2);
		}
		if (!$this->suppresselevation && sizeof($this->jeles)){
			$segment->stats->elevation = new stdClass();
			$segment->stats->elevation->max = max($this->jeles);
			$segment->stats->elevation->min = min($this->jeles);
			$segment->stats->elevation->avgChange = round(array_sum($this->jeledevs)/count($this->jeledevs),2);
		}
		if (!$this->suppresslocation){
			$this->journey->stats->distanceTravelled = array_sum($this->jdist); // See GPXIN-6
		}
		// Add any relevant metadata
		$this->journey->metadata = new stdClass();
		$this->journey->metadata->smartTrackStatus = ($this->smartTrackStatus())? 'enabled' : 'disabled';
		$this->journey->metadata->smartTrackThreshold = $this->smartTrackThreshold();
		$this->journey->metadata->suppression = array();
		// Add a version number so the object can be used to identify which stats will/won't be present
		$this->journey->metadata->GPXIngestVersion = $this->ingest_version;

		// Add a list of the supported experimental features and whether they were enabled
		$this->journey->metadata->experimentalFeatureState = $this->listExperimental();
		$this->writeSuppressionMetadata();

		// XML Ingest and conversion done!
	}




	/** Calculate the rate of (ac|de)celeration and update the relevant stats arrays.
	* Also returns the values
	*
	* All returned values should be considered m/s^2 (i.e. the standard instrument)
	*
	* @arg - Speed (not including Unit -i.e. 1 not 1 KPH or MPH)
	* @arg - timestamp of the speed recording
	* @arg - Unit of measurement (i.e. kph)
	*
	* @return array - acceleration and deceleration.
	*
	*/
	protected function calculateAcceleration($speed,$timestamp,$unit){
		$acceleration = 0;
		$deceleration = 0;

		// We need to convert the speed into metres per sec


		if ($unit == 'kph'){
			// I'm screwed if my logic is wrong here
			// KPH -> m/s = x kph * 1000 = x metres per hour / 3600 = x metres per second

			$speed = ((int)$speed* 1000)/3600;
		}else{
			// MPH.
			// There are 1609.344 metres to a mile, suspect we may need some rounding done on this one
			$speed = ((int)filter_var($speed, FILTER_SANITIZE_NUMBER_INT)* 1609.344)/3600;
		}



		// Can't calculate acceleration if we don't have a previous timestamp or speed. Also don't want to falsely record acc/dec if the speed is the same.
		if (!$this->lasttimestamp || !$this->lastspeedm || $speed == $this->lastspeed){
			$this->lastspeedm = $speed;
			return array($acceleration,$deceleration);
		}

		// We use the formula
		// (fV - iV)/t
		// We'll worry about whether it's accel or decel after doing the maths
		$velocity_change = $this->entryperiod > 0 ?
			($speed - $this->lastspeedm) / $this->entryperiod :
			0;

		if ($velocity_change < 0){
			// It's deceleration
			$deceleration = round(($velocity_change*-1),4);
			$this->fdecel[] = $deceleration;
			$this->decels[] = $deceleration;
			$this->timedecel = $this->timedecel + $this->entryperiod;
		}else{
			// It's acceleration
			$acceleration = round($velocity_change,4);
			$this->faccel[] = $acceleration;
			$this->accels[] = $acceleration;

			if ($velocity_change != 0){
				$this->timeaccel = $this->timeaccel + $this->entryperiod;
			}
		}
		$this->lastspeedm = $speed;
		$this->lasttimestamp = $timestamp;
		return array($acceleration,$deceleration);
	}


	/** Update the Journey object's metadata to include details of what information (if any) was suppressed at ingest
	*
	*/
	protected function writeSuppressionMetadata(){

		if ($this->suppresslocation){
			$this->journey->metadata->suppression[] = 'location';
		}
		if ($this->suppressspeed){
			$this->journey->metadata->suppression[] = 'speed';
		}
		if ($this->suppresselevation){
			$this->journey->metadata->suppression[] = 'elevation';
		}
		if ($this->suppressdate){
			$this->journey->metadata->suppression[] = 'dates';
		}
		if ($this->suppresswptlocation){
			$this->journey->metadata->suppression[] = 'wptlocation';
		}
		if ($this->suppresswptele){
			$this->journey->metadata->suppression[] = 'wptele';		
		}

	}


	/** Initialise a Segment object
	*
	*/
	protected function initSegment(){
		$this->lasttimestamp = false;
		$this->lastpos = false;
		$this->entryperiod = 0;
		$this->segmentlats = array();
		$this->segmentlons = array();
	}



	/** add a track object
	*
	*/
	protected function addActivity($trk){
		$trackcounter = count($this->journey->journeys);
		$jkey = "journey$trackcounter";

		$this->journey->journeys[$jkey] = new TCXActivity($jkey, $trk);

		// Used by the Acceleration calculation method
		$this->lasttimestamp = false;

        	$this->tracklats = array(); //GPXIN-26
        	$this->tracklons = array(); //GPXIN-26
		return $this->journey->journeys[$jkey] ;
	}



	/** Write stats for the current segment
	*
	*/
	protected function writeSegmentStats(&$activity, &$segment,$times,$x,$uom,$timemoving,$timestationary){
		if (!$this->suppressspeed){
			$modesearch = array_count_values($this->sspeed);
			if ($x!=0)
				$segment->stats->avgspeed = round($this->speed/$x,2);
			if (is_array($modesearch) && count($modesearch))	
				$segment->stats->modalSpeed = array_search(max($modesearch), $modesearch);
			if (is_array($this->sspeed) && count($this->sspeed))
			{
				$segment->stats->minSpeed = min($this->sspeed);
				$segment->stats->maxSpeed = max($this->sspeed);
			}	
			$segment->stats->speedUoM = $uom;
		}

		// Calculate the total distance travelled (feet)
		if (!$this->suppresslocation){
			$segment->stats->distanceTravelled = array_sum($this->sdist);
			$segment->stats->bounds = new buildBoundsObj(); //GPXIN-26
			$min = is_array($this->segmentlats) && count($this->segmentlats)? min($this->segmentlats): 0;
			$max = is_array($this->segmentlats) && count($this->segmentlats)? max($this->segmentlats): 0;
			$segment->stats->bounds->Lat->min = $min;
			$segment->stats->bounds->Lat->max = $max;
			$segment->stats->bounds->Lon->min = $min;
			$segment->stats->bounds->Lon->max = $max;
		}

		if (!$this->suppressdate && sizeof($times)){
			$start = min($times);
			$end = max($times);
			$duration = $end - $start;
			$segment->stats->start = $start;
			$segment->stats->end = $end;
			$segment->stats->journeyDuration = $duration;

			// Increase the track duration by the time of our segment
			$activity->stats->journeyDuration = $activity->stats->journeyDuration + $duration;
			$this->trackduration = $this->trackduration + $activity->stats->journeyDuration;

			// We only need to add the min/max times to the track as we've already sorted the segment
			$this->ftimes[] = $segment->stats->start;
			$this->ftimes[] = $segment->stats->end;
		}

		if (!$this->suppresselevation && sizeof($this->seles)){
			$segment->stats->elevation = new \stdClass();
			$segment->stats->elevation->max = max($this->seles);
			$segment->stats->elevation->min = min($this->seles);
			$segment->stats->elevation->avgChange = round(array_sum($this->seledevs)/count($this->seledevs),2);
			$segment->stats->elevation->maxChange = max($this->seledevs);
			$segment->stats->elevation->minChange = min($this->seledevs);
		}

		// Update the stationary/moving stats
		$segment->stats->timeMoving = $timemoving;
		$segment->stats->timeStationary = $timestationary;
		$activity->stats->timeMoving = $activity->stats->timeMoving + $timemoving;
		$activity->stats->timeStationary = $activity->stats->timeStationary + $timestationary;
		$this->journey->stats->timeMoving = $this->journey->stats->timeMoving + $timemoving;
		$this->journey->stats->timeStationary = $this->journey->stats->timeStationary + $timestationary;


		// Update the accel stats - has to assume you spent the chunk accelerating so may be inaccurate
		$segment->stats->timeAccelerating = $this->timeaccel;
		$segment->stats->timeDecelerating = $this->timedecel;
		$activity->stats->timeAccelerating = $activity->stats->timeAccelerating + $this->timeaccel;
		$activity->stats->timeDecelerating = $activity->stats->timeDecelerating + $this->timedecel;
		$this->journey->stats->timeAccelerating = $this->journey->stats->timeAccelerating + $this->timeaccel;
		$this->journey->stats->timeDecelerating = $this->journey->stats->timeDecelerating + $this->timedecel;

	}


	/** Write stats for the current track
	*
	*/
	protected function writeTrackStats(&$activity){

		// If speed is suppressed we'll have pushed 1 into the array for each trackpart.
		$ptcount = count($this->fspeed);

		if (!$this->suppressspeed){
			$sumspeed = array_sum($this->fspeed);
			$modesearch = array_count_values($this->fspeed);
			$this->journeyspeeds = array_merge($this->journeyspeeds,$this->fspeed);
			$activity->stats->maxSpeed = max($this->fspeed);
			$activity->stats->minSpeed = min($this->fspeed);
			$activity->stats->modalSpeed = array_search(max($modesearch), $modesearch);
			$activity->stats->avgspeed = round($sumspeed/$ptcount,2);

			// Prevent warnings if empty - GPXIN-28
			$sanitycheck='';
			if (count($this->faccel) > 0){
				$activity->stats->maxacceleration = max($this->faccel);
				$activity->stats->minacceleration = min($this->faccel);
				$sanitycheck="1";
			}

			if (count($this->fdecel) > 0){
				$activity->stats->maxdeceleration = max($this->fdecel);
				$activity->stats->mindeceleration = min($this->fdecel);
				$sanitycheck.="1";
			}

			// Prevent div by 0
			if ($sanitycheck == "11"){
				$activity->stats->avgacceleration = round(array_sum($this->faccel)/count($this->faccel),2);
				$activity->stats->avgdeceleration = round(array_sum($this->fdecel)/count($this->fdecel),2);
			}

			// Add the calculated max/min speeds to the Journey wide stats
			if (is_array($this->fspeed) && count($this->fspeed))
			{
				$this->highspeeds[] = $activity->stats->maxSpeed;
				$this->lowspeeds[] = $activity->stats->minSpeed;
			}	
		}

		if (!$this->suppresslocation){
			$activity->stats->distanceTravelled = array_sum($this->fdist);
               $activity->stats->bounds = new buildBoundsObj(); //GPXIN-26
			$activity->stats->bounds->Lat->min = min($this->tracklats);
			$activity->stats->bounds->Lat->max = max($this->tracklats);
			$activity->stats->bounds->Lon->min = min($this->tracklons);
			$activity->stats->bounds->Lon->max = max($this->tracklons);
		}

		if (!$this->suppressdate && sizeof($this->ftimes)){
			// Finalise the track stats
			$activity->stats->start = min($this->ftimes);
			$activity->stats->end = max($this->ftimes);
			$activity->stats->recordedDuration = $this->trackduration;
			// Update the object times
			$this->totaltimes[] = $activity->stats->start;
			$this->totaltimes[] = $activity->stats->end;
			$this->journey->stats->recordedDuration = $this->journey->stats->recordedDuration + $this->trackduration;
		}

		if (!$this->suppresselevation && sizeof($this->feles)){
			$activity->stats->elevation = new \stdClass();
			$activity->stats->elevation->max = max($this->feles);
			$activity->stats->elevation->min = min($this->feles);
			$activity->stats->elevation->avgChange = round(array_sum($this->feledevs)/count($this->feledevs),2);
			$activity->stats->elevation->maxChange = max($this->feledevs);
			$activity->stats->elevation->minChange = min($this->feledevs);
			$activity->stats->elevation->gain = $this->calculateElevationGain($this->feles);
		}
	}

	/** Calculate the distance between two lat/lon points - see GPXIN-6
	*
	* @arg array - (lat,lon) - the previous position
	* @arg array - (lat,lon) - the current position
	*
	* @return distance travelled (feet)
	*/
	protected function calculateTravelledDistance($old,$new){
	  // Array mapping (for ease of reference)
          // lat1 - old[0]
          // lat2 - new[0]
	  // lon1 - old[1]
          // lon2 - new[1]

	  $theta = $old[1] - $new[1];
	  $dist = acos(sin(deg2rad($old[0])) * sin(deg2rad($new[0])) + cos(deg2rad($old[0])) * cos(deg2rad($new[0])) * cos(deg2rad($theta)));
	  $dist = rad2deg($dist);

	  //$res = round(($dist * 60 * 1.1515) * 5280,3); // Convert to feet and round to 3 decimal places
	  $res = $dist * 6371 ; 
	  return (is_nan($res))? 0 : $res;
	}


	/** Reset the track stats counter
	*
	*/
	protected function resetTrackStats(){
		$this->ftimes = array();
		$this->fspeed = array();
		$this->trackduration = 0;
		$this->faccel = array();
		$this->fdecel = array();
		$this->feles = array();
		$this->feledevs = array();
		$this->fdist = array();
		$this->tracklats = array();
		$this->tracklons = array();
	}


	/** Reset the segments stats counter
	*
	*/
	protected function resetSegmentStats(){
		$this->speed = 0;
		$this->sspeed = array();
		$this->seles = array();
		$this->seledevs = array();
		$this->timeaccel = 0;
		$this->timedecel = 0;
		$this->sdist = array();
	}





	/**               ---- Information Retrieval functions ----                     **/



	/** Get the Journey object's metadata
	*
	* @return stdClass
	*
	*/
	public function getMetadata(){
		return $this->journey->metadata;
	}


	
	/** Get ID's of any ingested routes
	*
	* @return array
	*
	*/
	public function getRouteIDs(){
		if (!isset($this->journey->related->routes)){
			return array();
		}

		return array_keys((array) $this->journey->related->routes);		
	}

	/** Get ID's and names of any ingested routes
	*
	* @return array
	*
	*/
	public function getRouteNames(){
		$routes = array();

		if (!isset($this->journey->related->routes)){
			return $routes;
		}

		foreach ($this->journey->related->routes as $k => $v){
			$routes[] = array('id'=>$k,'name'=>$v->name);
		}

		return $routes;
	}

	
	/** Return a copy of the routes object
	*
	* @return stdclass
	*
	*/
	public function getRoutesObject(){
                return $this->journey->related->routes;
	}
	
	
	/** Driver method to keep b/c but also to make more consistent with naming of track related methods
	*
	* @return mixed
	*/
	public function getRoute($id){
            return $this->getRouteByID($id);
	}
	

	/** Get a route based on it's ID - DEPRECATED
	*
	* @return mixed
	*
	*/
	public function getRouteByID($id){
		if (isset($this->journey->related->routes->$id)){
			return $this->journey->related->routes->$id;
		}

		return false;
	}


	/** Retrieve a routepoint object
	*
	* @arg route - the route ID
	* @arg routepoint - the routepoint ID
	*
	*/
	public function getRoutePoint($route,$routepoint){
		return $this->journey->related->routes->$route->points->$routepoint;
	}
	

	/** Get Route statistics
	*
	* @return object
	*/
	public function getRouteStats(){
		return $this->journey->stats->routestats;
	}


	/** Get the routepoint ID's
	*
	* @arg track - the Track ID
	* @arg segment - the segment ID
	*
	*/
	public function getRoutePointNames($route){
		return array_keys((array) $this->journey->related->routes->$route->points);
	}

	/** Get the time the original GPX file was created
	*
	* @return INT - UNIX Epoch
	*
	*/
	public function getGPXTime(){
		return $this->journey->created->time;
	}



	/** Get the timezone used when the JSON object was encoded - previous user may not have set to UTC
	*
	* @return string
	*
	*/
	public function getGPXTimeZone(){
		return $this->journey->timezone;
	}


	/** Get the XML Namespaces that were defined in the source GPX file
	*
	* @return string
	*
	*/
	public function getGPXNameSpaces(){
		return $this->journey->created->namespaces;
	}


	/** Get any waypoints which were ingested (GPXIN-24)
	*
	* @return array
	*
	*/
	public function getWaypoints(){
		return $this->journey->related->waypoints->points;
	}

	/** Get a waypoint which were ingested (GPXIN-24)
	*
	* @arg INT - Key of the requested waypoing
	*
	* @return stdClass
	*
	*/
	public function getWaypoint($id){
		return $this->journey->related->waypoints->points[$id];
	}

	/**                  ----    Statistics retrieval  ----                      */


	/** Get a count of the recorded way points
	*
	*
	* @return INT
	*
	*/
	public function getWayPointCount(){
		return $this->journey->metadata->waypoints;
	}


	/** Get a count of the recorded routes
	*
	*
	* @return INT
	*
	*/
	public function getRouteCount(){
		return $this->journey->metadata->routes;
	}



	/** Get the overall statistics
	*
	* @return object
	*/
	public function getJourneyStats(){
		return $this->journey->stats;
	}



	/** Get the overall average speed
	*
	* @return decimal
	*
	*/
	public function getTotalAvgSpeed(){
		return $this->journey->stats->avgspeed;
	}

	/** Get the activity  object from either a track or a segment
	*
	* @arg track - the Track ID
	* @arg segment - the segment ID
	*
	*/
	public function getActivity($key){
		return $this->journey->journeys[$key];
	}


	/** Get the stats object from either a track or a segment
	*
	* @arg track - the Track ID
	* @arg segment - the segment ID
	*
	*/
	public function getStats($track,$segment=false){
		if (!$segment){
			return $this->journey->journeys[$track]->stats;
		}

		return $this->journey->journeys->$journeys[$track]->segments->$segment->stats;
	}



	/** Get the Journey start time
	*
	* @return INT - UNIX epoch
	*
	*/
	public function getJourneyStart(){
		return $this->journey->stats->start;
	}




	/** Get the Journey end time
	*
	* @return INT - UNIX epoch
	*
	*/
	public function getJourneyEnd(){
		return $this->journey->stats->end;
	}




	/** Get the average speed recorded for a journeys[$track] (or a segment within that journeys[$track])
	*
	* @arg journeys[$track] - the journeys[$track] ID
	* @arg segment - the segment ID
	*
	*/
	public function getAvgSpeed($track, $segment=false){

		if (!$segment){
			return $this->journey->journeys[$track]->stats->avgspeed;
		}

		return $this->journey->journeys[$track]->segments->$segment->stats->avgspeed;

	}






	/**                     ----   OUTPUT FUNCTIONS   ----                   **/




	/** Retrieve a journeys[$track] object
	*
	* @arg journeys[$track] - the journeys[$track] ID
	*
	* @return object
	*/
	public function getjourneystrack($track){
		return $this->journey->journeys[$track];
	}




	/** Retrieve a segment object
	*
	* @arg journeys[$track] - the journeys[$track] ID
	* @arg segment - the segment ID
	*
	* @return object
	*/
	public function getSegment($track,$segment){
		return $this->journey->journeys[$track]->segments[$segment];
	}





	/** Retrieve a journeys[$track]point object
	*
	* @arg journeys[$track] - the journeys[$track] ID
	* @arg segment - the segment ID
	* @arg journeys[$track]point - the journeys[$track]point ID
	*
	*/
	public function getjourneysPoint($track,$segment,$point){
		return $this->journey->journeys[$track]->segments[$segment]->points[$point];
	}



	/** Get the generated object in JSON encapsulated format
	*
	* @return string
	*
	*/
	public function getJSON(){
		return json_encode($this->journey);
	}




	/** Get the journey object
	*
	* @return object
	*
	*/
	public function getObject(){
		return $this->journey;
	}



	/**           ----   Suppression Functions    ----    */

	/** Suppress elements of the data
	*
	*/
	public function suppress($ele){

		switch($ele){
			case 'location':
				$this->suppresslocation = true;
                                break;

			case 'speed':
				$this->suppressspeed = true;
                                break;

			case 'elevation':
				$this->suppresselevation = true;
                                break;

			case 'date':
				$this->suppressdate = true;
				break;
				
                        case 'wptlocation':
                        	$this->suppresswptlocation = true;
                                break;
                        	
                        case 'wptele':
                                $this->suppresssuppresswptele = true;
                                break;

                        case 'calcdistance':
                                $this->suppresscalcdistance = true;
                                break;
                                

		}
	}



	/** Unsuppress elements of the data
	*
	*/
	public function unsuppress($ele){

		switch($ele){
			case 'location':
				$this->suppresslocation = false;
                                break;

			case 'speed':
				$this->suppressspeed = false;
                                break;

			case 'elevation':
				$this->suppresselevation = false;
                                break;

			case 'date':
				$this->suppressdate = false;
                                break;
				
                        case 'wptlocation':
                        	$this->suppresswptlocation = false;
                                break;
                        	
                        case 'wptele':
                                $this->suppresssuppresswptele = false;
                                break;
                                
                        case 'calcdistance':
                                $this->suppresscalcdistance = false;
                                break;                                

		}
	}
	
		/** Identify whether an experimental feature has been enabled
	*
	* @arg type
	*
	* @return boolean
	*/
	protected function expisenabled($type){
	      return isset($this->featuretoggle[$type]);
	}



	/** Enable functionality which is considered experimental or computationally expensive
	*
	* @arg type - the element to enable
	*
	* @return void
	*/
	public function enableExperimental($type){

		if (in_array($type,$this->experimentalFeatures)){
		      $this->featuretoggle[$type] = 1;
		}
	}



	/** Disable functionality which is considered experimental or computationally expensive
	*
	* @arg type - the element to enable
	*
	* @return void
	*/
	public function disableExperimental($type){
		if (isset($this->featuretoggle[$type])){
		      unset($this->featuretoggle[$type]);
		}
	}



	/** List experimental features and indicate whether they are currently enabled
	*
	*
	* @return array
	*/
	public function listExperimental(){

		$resp = array();

		foreach ($this->experimentalFeatures as $feature){
			$resp[$feature] = (isset($this->featuretoggle[$feature]))? 1 : 0;
		}

		return $resp;
	}
	
	/**
	 * Calculate total elevation gain
	 *
	 * @param $elevationData Array with elevation data
	 * @return float|int Total elevation gain in meters
	 */
	public function calculateElevationGain($elevationData) {

	    if (!$this->expisenabled('calcElevationGain')){ // This functionality is currently considered experimental
		    return 0;
	    }

		$gain = 0;
		$prev = (float)$elevationData[0];
		foreach ($elevationData as $elevation) {
			if ( ($new = (float)$elevation) > $prev) {
				$gain += $new - $prev;
			}
			$prev = $new;
		}
		return $gain;
	}

	
	public function getSegmentscount($track) {		
		return count($this->journey->journeys[$track]->segments);
	}
	
	public function getjourneyscount() {		
		return count($this->journey->journeys);
	}
	
	public function computereport($jkey) {
		$totaltime = 0;	
		$totaldistance = 0;
		$stats = $this->getActivity($jkey)->stats;
		foreach ($stats->laps as $lap){				
			$totaltime +=  (int)$lap->TotalTimeSeconds;
			$totaldistance += (float)$lap->DistanceMeters;													
		}
		$totalspeed = (3600 * $totaldistance)/$totaltime;
		$this->getActivity($jkey)->stats->totalspeed = $totalspeed;
		$this->getActivity($jkey)->stats->totaltime = $totaltime;
		$this->getActivity($jkey)->stats->totaldistance = $totaldistance;
		//print_r($this->getActivity($jkey)->laps);
	}


}