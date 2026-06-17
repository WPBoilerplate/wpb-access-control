/**
 * Checkbox list for providers that expose static options (e.g. wp_role).
 */

const PROVIDER_DESCRIPTIONS = {
	wp_role:
		'Select which WordPress Role values may access this resource. Leave all unchecked to deny everyone (except administrators).',
	wp_capability:
		'Select which WordPress capabilities grant access. Users holding any of the checked capabilities are allowed. Administrators always have access.',
	bb_profile_type:
		'Select which BuddyBoss profile types grant access. Users assigned to any of the checked profile types are allowed. Administrators always have access.',
	mepr_membership:
		'Select which MemberPress memberships grant access. Users with an active subscription to any of the checked memberships are allowed. Administrators always have access.',
};

/**
 * @param {Object}   props
 * @param {string}   props.providerId       Active provider ID (used for description lookup).
 * @param {Array}    props.options           Options from provider.options [{id, label}].
 * @param {string[]} props.selectedOptions  Currently checked option IDs.
 * @param {Function} props.onToggle         Called with option ID when a checkbox changes.
 */
export default function RoleOptionsPanel( {
	providerId,
	options,
	selectedOptions,
	onToggle,
} ) {
	const description = PROVIDER_DESCRIPTIONS[ providerId ] || null;

	return (
		<div className="wpb-ac__options-panel">
			{ description && (
				<p className="wpb-ac__panel-description">{ description }</p>
			) }
			<ul className="wpb-ac__checkbox-list">
				{ options.map( ( option ) => (
					<li key={ option.id } className="wpb-ac__checkbox-item">
						<label>
							<input
								type="checkbox"
								value={ option.id }
								checked={ selectedOptions.includes( option.id ) }
								onChange={ () => onToggle( option.id ) }
							/>
							{ option.label }
						</label>
					</li>
				) ) }
			</ul>
		</div>
	);
}
