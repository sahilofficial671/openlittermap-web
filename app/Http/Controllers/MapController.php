<?php

namespace App\Http\Controllers;

use App\Models\User\User;
use App\Models\Photo;
use App\Models\Location\City;
use App\Models\Location\State;
use App\Models\Location\Country;
use App\DynamicLoading;

use Log;
use JavaScript;
use Carbon\Carbon;
use App\GlobalLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class MapController extends Controller
{
	// Get Leaderboard and location creator for each location
	use DynamicLoading;

	/**
	 * Get the Maps & Data page, incl all Countries metadata
	 */
	public function getCountries ()
	{
		// first - global metadata
		$littercoin = \DB::table('users')->sum(\DB::raw('littercoin_owed + littercoin_allowance'));

		/**
		 *  Todo
		 	1. save user_id in country created_by column
		 	2. Find out how to get top-10 more efficiently
            3. Paginate
            4. Automate 'manual_verify => 1'
            5. Eager load leaders with the country model
         */
		$countries = Country::with(['creator' => function($q) {
			$q->select('id', 'name', 'username', 'show_name_createdby', 'show_username_createdby')
			  ->where('show_name_createdby', true)
			  ->orWhere('show_username_createdby', true);
			}])
		->where('manual_verify', '1')
		->orderBy('country', 'asc')
        ->get();

		$total_litter = 0;
		$total_photos = 0;

		foreach ($countries as $country)
		{
            // Get Creator info
            $country = $this->getCreatorInfo($country);

            // Get Leaderboard per country. Should load more and stop when there are 10-max as some users settings may be off.
			$leaderboard_ids = Redis::zrevrange($country->country.':Leaderboard', 0, 9);

			$leaders = User::whereIn('id', $leaderboard_ids)->orderBy('xp', 'desc')->get();

			$arrayOfLeaders = $this->getLeaders($leaders);

        	$country['leaderboard'] = json_encode($arrayOfLeaders);

        	// Total values
        	$country['avg_photo_per_user'] = round($country->total_photos_redis / $country->total_contributors, 2);
        	$country['avg_litter_per_user'] = round($country->total_litter_redis / $country->total_contributors, 2);

            $total_photos += $country->total_photos_redis;
            $total_litter += $country->total_litter_redis;

        	$country['diffForHumans'] = $country->created_at->diffForHumans();
	    }

        /**
         * Global levels
         *
         * todo - Make this dynamic
         * See: GlobalLevels.php global_levels table
         * We need to keep earlier levels for test databases
         */
        // level 0
        if ($total_litter <= 1000)
        {
            $previousXp = 0;
            $nextXp = 1000;
        }
        // level 1 - target, 10,000
        else if ($total_litter <= 10000)
        {
            $previousXp = 1000;
            $nextXp = 10000; // 10,000
        }
        // level 2 - target, 100,000
    	else if ($total_litter <= 100000)
    	{
    		$previousXp = 10000; // 10,000
    		$nextXp = 100000; // 100,000
    	}
    	// level 3 - target 250,000
        else if ($total_litter <= 250000)
        {
            $previousXp = 100000; // 100,000
            $nextXp = 250000; // 250,000
        }
        // level 4 500,000
        else if ($total_litter <= 500000)
        {
            $previousXp = 250000; // 250,000
            $nextXp = 500000; // 500,000
        }
        // level 5, 1M
        else if ($total_litter <= 1000000)
        {
            $previousXp = 250000; // 250,000
            $nextXp = 1000000; // 500,000
        }

        /** GLOBAL LITTER MAPPERS */
	    $users = User::where('xp', '>', 9000)
            ->orderBy('xp', 'desc')
            ->where('show_name', 1)
            ->orWhere('show_username', 1)
            ->limit(10)
            ->get();

	    $newIndex = 0;
	    $globalLeaders = [];
	    foreach ($users as $user)
	    {
            $name = '';
            $username = '';
            if (($user->show_name) || ($user->show_username))
            {
                if ($user->show_name) $name = $user->name;

                if ($user->show_username) $username = '@' . $user->username;

                $globalLeaders[$newIndex] = [
                    'position' => $newIndex,
                    'name' => $name,
                    'username' => $username,
                    'xp' => number_format($user->xp),
                    'flag' => $user->global_flag
                    // 'level' => $user->level,
                    // 'linkinsta' => $user->link_instagram
                ];

                $newIndex++;
            }
        }

        $globalLeadersString = json_encode($globalLeaders);

        return [
            'countries' => $countries,
            'total_litter' => $total_litter,
            'total_photos' => $total_photos,
            'globalLeaders' => $globalLeadersString,
            'previousXp' => $previousXp,
            'nextXp' => $nextXp,
            'littercoin' => $littercoin,
            'owed' => 0
        ];
    }

	/**
	 * Get States for a country
     *
     * @return array
	 */
	public function getStates () :array
	{
        $country_name = urldecode(request()->country);

		$country = Country::where('country', $country_name)
			->orWhere('shortcode', $country_name)
			->first();

		$states = State::select('id', 'state', 'country_id', 'created_by', 'created_at', 'manual_verify', 'total_contributors')
            ->with(['creator' => function ($q) {
				$q->select('id', 'name', 'username', 'show_name', 'show_username')
				  ->where('show_name', true)
			  	  ->orWhere('show_username', true);
			}])
            ->where([
				'country_id' => $country->id,
				'manual_verify' => 1,
                ['total_litter', '>', 0],
                ['total_contributors', '>', 0]
			])
            ->orderBy('state', 'asc')
            ->get();

		$total_litter = 0;
		$total_photos = 0;
		foreach ($states as $state)
		{
	        // Get Creator info
		    $state = $this->getCreatorInfo($state);

		    // Get Leaderboard
	        $leaderboard_ids = Redis::zrevrange($country->country.':'.$state->state.':Leaderboard',0,9);

            $leaders = User::whereIn('id', $leaderboard_ids)->orderBy('xp', 'desc')->get();

            $arrayOfLeaders = $this->getLeaders($leaders);

        	$state->leaderboard = json_encode($arrayOfLeaders);

        	// Get images/litter metadata
        	$state->avg_photo_per_user = round($state->total_photos_redis / $state->total_contributors, 2);
        	$state->avg_litter_per_user = round($state->total_litter_redis / $state->total_contributors, 2);

        	$total_litter += $state->total_litter_redis;
        	$state->diffForHumans = $state->created_at->diffForHumans();

            if ($state->creator)
            {
                $state->creator->name = ($state->creator->show_name) ? $state->creator->name : "";
                $state->creator->username = ($state->creator->show_username) ? $state->creator->username : "";
            }
	    }

		return [
			'country' => $country->country,
			'states' => $states,
			'total_litter' => $total_litter,
			'total_photos' => $total_photos
		];
	}

	/**
	 * Get the cities for the /country/state
	 */
	public function getCities ()
	{
        $country_name = urldecode(request()->country);
        $state_name = urldecode(request()->state);

        $country = Country::where('country', $country_name)->first();

		$state = State::where([
			['state', $state_name],
			['total_images', '!=', null]
		])->first();

        /**
         * Instead of loading the photos here on the city model,
         * save photos_per_day string on the city model
         */
		$cities = City::select('id', 'city', 'country_id', 'state_id', 'created_by', 'created_at', 'manual_verify', 'total_contributors')
            ->with(['creator' => function ($q) {
			    $q->select('id', 'name', 'username', 'show_name', 'show_username')
                  ->where('show_name', true)
                  ->orWhere('show_username', true);
		    }])
            ->where([
                ['state_id', $state->id],
			    ['total_images', '>', 0],
                ['total_litter', '>', 0],
                ['total_contributors', '>', 0]
		    ])
            ->orderBy('city', 'asc')
            ->get();

		foreach ($cities as $city)
		{
            // Get Creator info
            $city = $this->getCreatorInfo($city);

            // Get Leaderboard
            $leaderboard_ids = Redis::zrevrange($country . ':' . $state->state . ':' . $city->city . ':Leaderboard', 0, 9);

            $leaders = User::whereIn('id', $leaderboard_ids)->orderBy('xp', 'desc')->get();

            $arrayOfLeaders = $this->getLeaders($leaders);

            $city['leaderboard'] = json_encode($arrayOfLeaders);
            $city['avg_photo_per_user'] = round($city->total_photos_redis / $city->total_contributors, 2);
            $city['avg_litter_per_user'] = round($city->total_litter_redis / $city->total_contributors, 2);
            $city['diffForHumans'] = $city->created_at->diffForHumans();

            if ($city->creator)
            {
                $city->creator->name = ($city->creator->show_name) ? $city->creator->name : "";
                $city->creator->username = ($city->creator->show_username) ? $city->creator->username : "";
            }
        }

		return [
			'country' => $country->country,
			'state' => $state->state,
			'cities' => $cities
		];
	}

	/**
	 * Load the City data, maybe pass a filtered city request.
	 */
	public function getCity ()
    {
        $country = urldecode(request()->country);
        $state = urldecode(request()->state);
        $city = urldecode(request()->city);

        $minFilt = null;
        $maxFilt = null;
        $hex = 100;

		if (request()->min)
		{
			$minFilt = str_replace('-', ':', request()->min);
			$maxFilt = str_replace('-', ':', request()->max);
			$hex = request()->hex;
		}

		$litterGeojson = self::buildGeojson($city, $minFilt, $maxFilt);

		return [
			  'center_map' => $this->latlong,
				'map_zoom' => 13,
		   'litterGeojson' => $litterGeojson,
				   	 'hex' => $hex
		];
	}


	/**
	 * Dynamically build GeoJSON data for web-mapping
	 */
	private function buildGeojson ($city, $minfilter = null, $maxfilter = null)
	{
		$cityId = City::where('city', $city)->first()->id;

		if ($minfilter)
		{
			$minTime = \DateTime::createFromFormat('d:m:Y', $minfilter)->format('Y-m-d 00:00:00'); // 0018-mm-dd 00:00:00
			$maxTime = \DateTime::createFromFormat('d:m:Y', $maxfilter)->format('Y-m-d 23:59:59');

			$minTime = substr_replace($minTime,'2',0,1); //  2018-mm-dd hh:mm:ss
		    $maxTime = substr_replace($maxTime,'2',0,1);

			$photoData = Photo::with([
				'smoking',
				'food',
				'coffee',
				'alcohol',
				'softdrinks',
				'sanitary',
				'other',
				'coastal',
				'brands',
				'dumping',
				'industrial',
//				 'art',
//				'trashdog',
				'user' => function ($q) {
					$q->where('show_name_maps', true)
                      ->orWhere('show_username_maps', true);
				}])->where([
                    ['city_id', $cityId],
                    ['verified', '>', 0],
                    ['datetime', '>=', $minTime],
                    ['datetime', '<=', $maxTime]
			])->orderBy('datetime', 'asc')->get();

			$this->getInitialPhotoLatLon($photoData[0]);
			$this->photoCount = $photoData->count();

		} else {

			$photoData = Photo::with([
				'smoking',
				'food',
				'coffee',
				'alcohol',
				'softdrinks',
				'sanitary',
				'other',
				'coastal',
				'brands',
				'dumping',
				'industrial',
//				 'art',
//				'trashdog',
				'user' => function ($q) {
					$q->where('show_name_maps', true)->orWhere('show_username_maps', true);
				}])->where([
					['city_id', $cityId],
					['verified', '>', 0]
				])->orderBy('datetime', 'asc')->get();

			$this->getInitialPhotoLatLon($photoData[0]);
			$this->photoCount = $photoData->count();
		}

		$geojson = array(
   			'type'      => 'FeatureCollection',
   			'features'  => array()
		);

		foreach ($photoData as $c)
		{
			$feature = array(
				'type' => 'Feature',
				'geometry' => array(
					'type' => 'Point',
					'coordinates' => array($c["lon"], $c["lat"])
				),

			'properties' => array(
				   'photo_id' => $c["id"],
				   'filename' => $c["filename"],
					  'model' => $c["model"],
				   'datetime' => $c["datetime"],
					    'lat' => $c["lat"],
					    'lon' => $c["lon"],
			       'verified' => $c["verified"],
				  'remaining' => $c["remaining"],
			   'display_name' => $c["display_name"],

					// data
					'smoking' => $c->smoking,
					   'food' => $c->food,
					 'coffee' => $c->coffee,
					'alcohol' => $c->alcohol,
				 'softdrinks' => $c->softdrinks,
					  'drugs' => $c->drugs,
				   'sanitary' => $c->sanitary,
					  'other' => $c->other,
					'coastal' => $c->coastal,
					'pathway' => $c->pathway,
//						'art' => $c->art,
					 'brands' => $c->brands,
					'dumping' => $c->dumping,
				 'industrial' => $c->industrial,
//				   'trashdog' => $c->trashdog,
			   'total_litter' => $c->total_litter
				)
			);

			if ($c->user)
			{
				if ($c->user->show_name_maps) {
					$feature["properties"]["fullname"] = $c->user->name;;
				}
				if ($c->user->show_username_maps) {
					$feature["properties"]["username"] = $c->user->username;;
				}
			}

			// Add features to feature collection array
			array_push($geojson["features"], $feature);
		}

		json_encode($geojson, JSON_NUMERIC_CHECK);

		return $geojson;
	}

	/**
	 * Global data for main page
	 */
	public function getGlobalData (Request $request)
	{
		$date = $request->date;

		if ($date == 'today')
		{
			$data = Photo::where('verified', '>', 0)
				->whereDate('created_at', Carbon::today())
				->get();
		}

		else if ($date == 'one-week')
		{
			$data = Photo::where('verified', '>', 0)
				->whereDate('created_at', '>', Carbon::today()->subDays(7))
				->get();
		}

		else if ($date == 'one-month')
		{
			$data = Photo::where('verified', '>', 0)
				->whereDate('created_at', '>', Carbon::today()->subMonths(1))
				->get();
		}

		else if ($date == 'one-year')
		{
			$data = Photo::where('verified', '>', 0)
				->whereDate('created_at', '>', Carbon::today()->subYear(1))
				->get();
		}

		else if ($date == 'all-time')
		{
			// all time
			$data = Photo::where('verified', '>', 0)->get();
		}

		else return 'come back later!';

		// create FC object
		$geojson = array(
   			'type'      => 'FeatureCollection',
   			'features'  => array()
		);

		// Populate geojson object
		foreach($data as $c)
		{
			// if($c['art_id']) {
			// 	$art = \App\Models\Litter\Categories\Art::find($c['art_id']);
			// } else {
			// 	$art = 'null';
			// }
			// if($c['trashdog_id']) {
			// 	$trashdog = \App\Models\Litter\Categories\TrashDog::find($c['trashdog_id']);
			// } else {
			// 	$trashdog = 'null';
			// }

			$feature = array(
				'type' => 'Feature',
				'geometry' => array(
					       'type' => 'Point',
					'coordinates' => array($c["lon"], $c["lat"])
				),

				'properties' => array(
					   'photo_id' => $c["id"],
					   'filename' => $c["filename"],
						  'model' => $c["model"],
					   'datetime' => $c["datetime"],
						    'lat' => $c["lat"],
						    'lon' => $c["lon"],
				  'result_string' => $c["result_string"],

						// data
							// 'art' => $art,
					   // 'trashdog' => $trashdog,
				)
			);
			// Add features to feature collection array
			array_push($geojson["features"], $feature);
		}

		json_encode($geojson, JSON_NUMERIC_CHECK);

		return [ 'geojson' => $geojson ];
	}

	/**
	 * Return global map (root html file)
	 */
	public function global ()
	{
		$locale = \Lang::locale();

		return view('layouts.globalmap', compact('locale'));
	}

}
