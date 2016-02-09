<?php

class AnsiTheme extends \SensioLabs\AnsiConverter\Theme\Theme
{
	public function asArray()
	{
		return array(
			// normal
			'black' => '#ffffff',
			'red' => '#dc322f',
			'green' => '#859900',
			'yellow' => '#b58900',
			'blue' => '#268bd2',
			'magenta' => '#d33682',
			'cyan' => '#2aa198',
			'white' => '#000000',

			// bright
			'brblack' => '#002b36',
			'brred' => '#cb4b16',
			'brgreen' => '#586e75',
			'bryellow' => '#657b83',
			'brblue' => '#839496',
			'brmagenta' => '#6c71c4',
			'brcyan' => '#93a1a1',
			'brwhite' => '#fdf6e3',
		);
	}
}
