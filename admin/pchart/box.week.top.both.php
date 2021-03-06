<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * LICENSE:
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * @categories	Games/Entertainment, Systems Administration
 * @package		Bright Game Panel
 * @author		warhawk3407 <warhawk3407@gmail.com> @NOSPAM
 * @copyleft	2013
 * @license		GNU General Public License version 3.0 (GPLv3)
 * @version		(Release 0) DEVELOPER BETA 5
 * @link		http://www.bgpanel.net/
 */



//Prevent direct access
if (!defined('LICENSE'))
{
	exit('Access Denied');
}



/*
 * CAT:Line chart
 *
 * WEEK
 *
 * Process file for CPU - RAM - LOAD AVERAGE charts
 */

if (!defined('CHARTTYPE'))
{
	exit("Error: CONST CHARTTYPE undefined");
}



if (!defined('SINGLEBOXMODE'))
{
	$sql = mysql_query( "SELECT `boxid`, `name` FROM `".DBPREFIX."box` ORDER BY `boxid`" );
}
else
{
	$sql = mysql_query( "SELECT `boxid`, `name` FROM `".DBPREFIX."box` WHERE `boxid` = '".SINGLEBOXMODE."' LIMIT 1" );
}



/* Create and populate the pData object */
$MyData = new pData();



while ($rowsSql = mysql_fetch_assoc($sql)) //For each box
{

	/**
	 * We draw the graph
	 */

	$data = mysql_query( "SELECT `timestamp`, `boxids`, `".CHARTTYPE."` FROM `".DBPREFIX."boxData` WHERE `timestamp` >= '".(time() - (60 * 60 * 24 * 7 + CRONDELAY))."' ORDER BY `id` ASC" );
	$n = 0;
	while ($rowsData = mysql_fetch_assoc($data)) //For each data
	{

		/**
		 * We have to retrieve the box rank from data
		 */

		$rankTable = explode(';', $rowsData['boxids']);

		$numKeys = count($rankTable);

		for ($i = 0; $i < $numKeys; $i++)
		{
			if ($rankTable[$i] == $rowsSql['boxid'])
			{
				$rank = $i; //Box data are the values at the rank $i
			}
		}

		unset($numKeys);

		//---------------------------------------------------------+

		if (isset($rank)) //We have data associated to the box
		{

			/**
			 * Synchronization
			 */

			if (mysql_num_rows($data) != $numPointsPerWeek) // Is there a gap in the graph ?
			{
				for ($i = $numPointsPerWeek - $n; $i > -1; $i--)
				{
					$time = $lastTimestamp - (CRONDELAY * $i);

					if (date('H:i, j-m-y', $rowsData['timestamp']) != date('H:i, j-m-y', $time))
					{
						$pointsTable[$n] = VOID; //Mark the gap
						$n++;
					}
					else
					{
						//Sync case
						$table = explode(';', $rowsData[CHARTTYPE]);
						if (empty($table[$rank]))
						{
							$pointsTable[$n] = VOID;
						}
						else
						{
							$pointsTable[$n] = $table[$rank];
						}
						break;
					}
				}
			}
			else
			{
				//Regular sync case
				$table = explode(';', $rowsData[CHARTTYPE]);
				if (empty($table[$rank]))
				{
					$pointsTable[$n] = VOID;
				}
				else
				{
					$pointsTable[$n] = $table[$rank];
				}
			}
		}
		else //No data for this box at this time
		{
			$pointsTable[$n] = VOID;
		}

		//---------------------------------------------------------+

		$n++;
	}
	unset($data, $n);

	//---------------------------------------------------------+

	$points[0] = VOID; //The first value is everytime a VOID one

	//---------------------------------------------------------+

	/**
	 * Average Computation
	 * 1 point = avg(1HOUR)
	 */

	$k = 0; // Point counter
	$i = 0; // Consecutive VOID values
	$j = 0; // Array key counter
	$averageSum = 0;

	foreach ($pointsTable as $value)
	{
		if ($value != VOID)
		{
			$averageSum += $value; // We prepare the sum of the values for the computation
		}
		else
		{
			$i++; // We increase our VOID value counter
		}

		$k++;

		if ($k == $numPointsPerHour)
		{
			$j++; // One hour has elapsed

			if ($k == $i) // No points for one hour
			{
				$points[$j] = VOID; // Mark the point as 'empty' with VOID key word
			}
			else
			{
				$points[$j] = round($averageSum / $numPointsPerHour, 2); // We compute the average and add it to our array
			}

			$k = 0;
			$i = 0;
			$averageSum = 0;
		}
	}
	unset($k, $i, $j, $averageSum, $pointsTable);

	$MyData->addPoints($points, $rowsSql['name']);
}
unset($sql);

//------------------------------------------------------------------------------------------------------------+
//------------------------------------------------------------------------------------------------------------+

if (CHARTTYPE == 'cpu')
{
	$MyData->setAxisName(0,"CPU Load");
	$MyData->setAxisUnit(0,"%");
}
else if (CHARTTYPE == 'ram')
{
	$MyData->setAxisName(0,"RAM Usage");
	$MyData->setAxisUnit(0,"%");
}
else
{
	$MyData->setAxisName(0,"Load Average");
}

//------------------------------------------------------------------------------------------------------------+
//------------------------------------------------------------------------------------------------------------+

/**
 * We draw the labels
 */

$k = 0;

for ($i = $numPointsPerWeek; $i > -1; $i--)
{
	$time = $lastTimestamp - (CRONDELAY * $i);

	if (($k == $numPointsPerHour) || ($i == $numPointsPerWeek) || ($i == 0))
	{
		$cronTime[$i] = ''; //Default Value

		if (date('H', $time) == '12') //Midday
		{
			$cronTime[$i] = date('H:i', $time);
			$midDay = date('H:i', $time);
		}
		else if (date('H', $time) == '00') //New Day
		{
			$cronTime[$i] = date('l H:i', $time);
		}

		$k = 0;
	}

	++$k;
}
unset($k);

$MyData->addPoints($cronTime, "Labels");

//------------------------------------------------------------------------------------------------------------+
//------------------------------------------------------------------------------------------------------------+

$MyData->setSerieDescription("Labels","Time");
$MyData->setAbscissa("Labels");

//------------------------------------------------------------------------------------------------------------+
//------------------------------------------------------------------------------------------------------------+

/* Create the pChart object */
$myPicture = new pImage(1130,366,$MyData);

/* Turn of Antialiasing */
$myPicture->Antialias = FALSE;

/* Add a border to the picture */
$myPicture->drawRectangle(0,0,1129,365,array("R"=>0,"G"=>0,"B"=>0));

//------------------------------------------------------------------------------------------------------------+
//------------------------------------------------------------------------------------------------------------+

/* Write the chart title */

$myPicture->setFontProperties(array("FontName"=>"../libs/pchart/fonts/Forgotte.ttf","FontSize"=>11));

if (CHARTTYPE == 'cpu')
{
	$myPicture->drawText(150,35,"CPU Load (Past Week)",array("FontSize"=>20,"Align"=>TEXT_ALIGN_BOTTOMMIDDLE));
}
else if (CHARTTYPE == 'ram')
{
	$myPicture->drawText(150,35,"RAM Usage (Past Week)",array("FontSize"=>20,"Align"=>TEXT_ALIGN_BOTTOMMIDDLE));
}
else
{
	$myPicture->drawText(155,35,"Load Average (Past Week)",array("FontSize"=>20,"Align"=>TEXT_ALIGN_BOTTOMMIDDLE));
}

//------------------------------------------------------------------------------------------------------------+
//------------------------------------------------------------------------------------------------------------+

/* Set the default font */
$myPicture->setFontProperties(array("FontName"=>"../libs/pchart/fonts/pf_arma_five.ttf","FontSize"=>6));

/* Define the chart area */
$myPicture->setGraphArea(60,40,1100,326);

/* Draw the scale */
$scaleSettings = array("XMargin"=>10,"YMargin"=>10,"Floating"=>TRUE,"DrawSubTicks"=>TRUE,"CycleBackground"=>TRUE);
$myPicture->drawScale($scaleSettings);

/* Write the chart legend */
$myPicture->drawLegend(512,20,array("Style"=>LEGEND_NOBORDER,"Mode"=>LEGEND_HORIZONTAL));

//------------------------------------------------------------------------------------------------------------+
//------------------------------------------------------------------------------------------------------------+

/* Draw label lines */

for ($i = 0; $i < $numPointsPerWeek; $i++)
{
	if (array_key_exists($i, $cronTime))
	{
		if (preg_match("#^[a-zA-Z]#", $cronTime[$i]))
		{
			$myPicture->drawXThreshold($cronTime[$i], array("ValueIsLabel"=>TRUE, "WriteCaption"=>FALSE, "Alpha"=>70, "Ticks"=>2, "R"=>0, "G"=>0, "B"=>255));
		}
	}
}

$myPicture->drawXThreshold($midDay, array("ValueIsLabel"=>TRUE, "Alpha"=>16,"Ticks"=>4));

//------------------------------------------------------------------------------------------------------------+
//------------------------------------------------------------------------------------------------------------+

/* Turn on Antialiasing */
$myPicture->Antialias = TRUE;

/* Draw the line chart */
$myPicture->drawLineChart();

/* Render the picture (choose the best way) */
$myPicture->autoOutput("./pcache/chart.".CHARTTYPE."week.png");

?>