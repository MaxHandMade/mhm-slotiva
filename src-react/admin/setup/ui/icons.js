/*
 * Küçük, dekoratif SVG'ler (aria-hidden): durum her zaman ayrıca metinle de verilir.
 */
const base = {
	viewBox: '0 0 24 24',
	fill: 'none',
	stroke: 'currentColor',
	strokeLinecap: 'round',
	strokeLinejoin: 'round',
	'aria-hidden': 'true',
	focusable: 'false',
};

export function CheckIcon( { size = 14, width = 3 } ) {
	return (
		<svg { ...base } width={ size } height={ size } strokeWidth={ width }>
			<path d="M5 12.5l4.5 4.5L19 7.5" />
		</svg>
	);
}

export function AlertIcon( { size = 16 } ) {
	return (
		<svg { ...base } width={ size } height={ size } strokeWidth="2.4">
			<path d="M12 8v5M12 16.5h.01" />
		</svg>
	);
}

export function DashIcon( { size = 16 } ) {
	return (
		<svg { ...base } width={ size } height={ size } strokeWidth="2.4">
			<path d="M7 12h10" />
		</svg>
	);
}

export function EyeIcon( { size = 16 } ) {
	return (
		<svg { ...base } width={ size } height={ size } strokeWidth="2">
			<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" />
			<circle cx="12" cy="12" r="3" />
		</svg>
	);
}

export function ArrowRightIcon( { size = 18 } ) {
	return (
		<svg { ...base } width={ size } height={ size } strokeWidth="2">
			<path d="M5 12h14M13 6l6 6-6 6" />
		</svg>
	);
}

export function ExternalIcon( { size = 16 } ) {
	return (
		<svg { ...base } width={ size } height={ size } strokeWidth="2">
			<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" />
		</svg>
	);
}

export function InfoIcon( { size = 18 } ) {
	return (
		<svg { ...base } width={ size } height={ size } strokeWidth="2">
			<circle cx="12" cy="12" r="9" />
			<path d="M12 11v5M12 7.5h.01" />
		</svg>
	);
}

// Service type icons (artboard Step1), 28px, drawn in the card accent colour.
export function KindIcon( { kind } ) {
	const paths = {
		consulting: <path d="M4 5h16v11H9l-5 4z" />,
		lesson: (
			<>
				<path d="M3 7l9-4 9 4-9 4z" />
				<path d="M7 9.5V15c0 1.5 2.2 3 5 3s5-1.5 5-3V9.5" />
			</>
		),
		session: (
			<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z" />
		),
		other: (
			<>
				<rect x="4" y="4" width="7" height="7" rx="1.5" />
				<rect x="13" y="4" width="7" height="7" rx="1.5" />
				<rect x="4" y="13" width="7" height="7" rx="1.5" />
				<rect x="13" y="13" width="7" height="7" rx="1.5" />
			</>
		),
	};
	return (
		<svg { ...base } width="28" height="28" strokeWidth="1.8">
			{ paths[ kind ] }
		</svg>
	);
}
