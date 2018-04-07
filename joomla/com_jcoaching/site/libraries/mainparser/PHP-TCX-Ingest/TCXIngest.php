<?php
/** GPXIngest Class
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
	//unlink($LOGFILE);
}
class TCXIngest extends GPXIngest{
	
	function initTrack($jkey,$trk)
	{
		parent::initTrack($jkey,$trk);
		$this->journey->journeys->$jkey->stats->desc = "";
		
	}
	
	public function getSegmentscount($track) {		
		//TCXIngest::my_log("segments:" . count($this->journey->journeys->$track->segments));
		return count($this->journey->journeys->$track->segments);
	}
	
	public function getjourneyscount() {		
		return count($this->journey->journeys);
	}
	
	public function computereport($jkey) {
		$totaltime = 0;	
		$totaldistance = 0;
		$stats = $this->journey->journeys->$jkey->stats;
		foreach ($stats->laps as $lap){				
			$totaltime +=  (int)$lap->TotalTimeSeconds;
			$totaldistance += (float)$lap->DistanceMeters;													
		}
		$totalspeed = (3600 *$totaldistance)/$totaltime;
		$this->journey->journeys->$jkey->stats->totalspeed = $totalspeed;
		$this->journey->journeys->$jkey->stats->totaltime = $totaltime;
		$this->journey->journeys->$jkey->stats->totaldistance = $totaldistance;
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

		if (!is_object($this->journey)){
		      $this->journey = new stdClass();
		}

		// Initialise the object
		$this->journey->created = new stdClass();
		$this->journey->stats = new stdClass();
		$this->journey->stats->trackpoints = 0;
		$this->journey->stats->recordedDuration = 0;
		$this->journey->stats->segments = 0;
		$this->journey->stats->tracks = 0;
		$this->journey->stats->maxacceleration = 0;
		$this->journey->stats->maxdeceleration = 0;
		$this->journey->stats->minacceleration = 0;
		$this->journey->stats->mindeceleration = 0;
		$this->journey->stats->avgacceleration = 0;
		$this->journey->stats->avgdeceleration = 0;
		$this->journey->stats->speedUoM = array();
		$this->journey->stats->timeMoving = 0;
		$this->journey->stats->timeStationary = 0;
		$this->journey->stats->timeAccelerating = 0;
		$this->journey->stats->timeDecelerating = 0;
		$this->journey->stats->distanceTravelled = 0;

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
		$unit = null;
		

		// Add the metadata
		$this->journey->created->creator = (string) $this->xml['creator'];
		$this->journey->created->version = (string) $this->xml['version'];
		$this->journey->created->format = 'GPX';

		if (!$this->suppressdate && isset($this->xml->time)){
			$this->journey->created->time = strtotime($this->xml->time);
		}

		$this->journey->timezone = date_default_timezone_get();

	
		$a = 0;
		// There may be multiple tracks in one file
		foreach ($this->xml->Activities->Activity as $trk){			
			// Initialise the stats variables
			$this->resetTrackStats();
			$b = 0;	
			// Set the object key
			//$jkey = "journey$a";
			$jkey = $this->genTrackKey($a);
			$this->initTrack($jkey,$trk->Id);
			$this->getStats($jkey)->summary = (string)$trk->attributes()['Sport'];	
			$this->getStats($jkey)->laps = array();
			foreach ($trk->Lap as $lap){				
			//specific for tcx
				array_push($this->getStats($jkey)->laps, (object)array('TotalTimeSeconds' => (string)$lap->TotalTimeSeconds,
														'DistanceMeters' => (string)$lap->DistanceMeters,
														'Speed' => (3600*$lap->DistanceMeters)/(int)$lap->TotalTimeSeconds));	
			// There may be multiple segments if GPS connectivity was lost - process each seperately
				foreach ($lap->Track as $trkseg){
				// Initialise the sub-stats variable
					$this->resetSegmentStats();
					$x = 0;
					$times = array();
					$lastele = false;
					$timemoving = 0;
					$timestationary = 0;

					// Set the segment key
					$segkey = $this->genSegKey($b);

					// Initialise the segment object
					$this->initSegment($jkey,$segkey);
					
					// Trackpoint details in trk - Push them into our object
					foreach ($trkseg->Trackpoint as $trkpt){
						
						// Initialise some variables
						$key = "trackpt$x";
						//TCXIngest::my_log($key);	
						$ptspeed = (int)filter_var($trkpt->desc, FILTER_SANITIZE_NUMBER_INT);
						$speed_string = (string) $trkpt->desc;
						//TCXIngest::my_log("trkpt:" .(string)$trkpt->Time);
						if (!$trkpt->desc){
						  $this->suppress('speed'); // Prevent warnings if speed is not available - See GPXIN-16
						}

						$time = strtotime((string)$trkpt->Time);
						
						if (!isset($this->journey->journeys->$jkey->segments->$segkey->points)){
							  $this->journey->journeys->$jkey->segments->$segkey->points = new stdClass();
						}
						
						$this->journey->journeys->$jkey->segments->$segkey->points->$key = new stdClass();
						// Calculate the period to which this trackpoint relates
						if ($this->lasttimestamp){
							$this->entryperiod = $time - $this->lasttimestamp;

							// Calculate time moving/stationary etc
							if ($this->lastspeed){

								if ($ptspeed > 0){
									$timemoving = $timemoving + $this->entryperiod;
								}else{
									$timestationary = $timestationary + $this->entryperiod;
								}

							}
						}

						// Write the track data - take into account whether we've suppressed any data elements
						if (!$this->suppresslocation){
							/*$lat = (string) $trkpt['lat']; // let's only caste once
							$lon = (string) $trkpt['lon'];

							$this->journey->journeys->$jkey->segments->$segkey->points->$key->lat = $lat;
							$this->journey->journeys->$jkey->segments->$segkey->points->$key->lon = $lon;*/

							/** Implemented in GPXIN-6 - currently experimental so will generally be 0 */
							
							
							$dist = ($this->lastpos)? $trkpt->DistanceMeters-$this->lastpos : 0;
							//TCXIngest::my_log(sprintf("%f",$dist));		
							$this->lastpos = $trkpt->DistanceMeters;
							// Update the stats arrays
							$this->fdist[] = $dist;
							$this->sdist[] = $dist;
							$this->jdist[] = $dist;
						}

						if (!$this->suppresselevation){
							$ele = (string) $trkpt->ele;
							$this->journey->journeys->$jkey->segments->$segkey->points->$key->elevation = $ele;

							$change = 0;
							if ($lastele){
								$change = $ele - $lastele;
							}

							$this->journey->journeys->$jkey->segments->$segkey->points->$key->elevationChange = $change;
							
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
							$this->journey->journeys->$jkey->segments->$segkey->points->$key->time = $time;
							// Update the times arrays
							$times[] = $time;
							//TCXIngest::my_log(sprintf("time:%s", $time));
							// update lasttime - used by SmartTrack
							$lasttime = $time;
						}


						if (!$this->suppressspeed){

							// What is the speed recorded in?
							$unit = strtolower(substr(rtrim($speed_string),strlen($speed_string)-3));


							$this->journey->journeys->$jkey->segments->$segkey->points->$key->speed = $speed_string;
							$this->journey->journeys->$jkey->segments->$segkey->points->$key->speedint = $ptspeed;

							// Calculate speed stats
							$this->speed = $this->speed + $ptspeed;
							$this->fspeed[] = $ptspeed;
							$this->sspeed[] = $ptspeed;


							// Calculate acceleration
							list($acc,$decc) = $this->calculateAcceleration($ptspeed,$time,$unit);
							$this->journey->journeys->$jkey->segments->$segkey->points->$key->acceleration = $acc;
							$this->journey->journeys->$jkey->segments->$segkey->points->$key->deceleration = $decc;

							// There shouldn't usually be more than one UoM per track file, but you never know - that's why it's an array
							if (!in_array($unit,$this->journey->stats->speedUoM)){
								$this->journey->stats->speedUoM[] = $unit;
							}

							// Tracks may also, plausibly, contain more than one measurement
							if (!in_array($unit,$this->journey->journeys->$jkey->stats->speedUoM)){
								$this->journey->journeys->$jkey->stats->speedUoM[] = $unit;
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

					$this->writeSegmentStats($jkey,$segkey,$times,$x,$unit,$timemoving,$timestationary);	
					$b++;
				}
			}
			$this->writeTrackStats($jkey);
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
			$this->journey->journeys->$jkey->segments->$segkey->stats->elevation = new stdClass();
			$this->journey->journeys->$jkey->segments->$segkey->stats->elevation->max = max($this->jeles);
			$this->journey->journeys->$jkey->segments->$segkey->stats->elevation->min = min($this->jeles);
			$this->journey->journeys->$jkey->segments->$segkey->stats->elevation->avgChange = round(array_sum($this->jeledevs)/count($this->jeledevs),2);
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



}