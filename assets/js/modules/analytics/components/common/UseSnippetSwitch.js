/**
 * Analytics Use Snippet Switch component.
 *
 * Site Kit by Google, Copyright 2021 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useContext, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Data from 'googlesitekit-data';
import { MODULES_ANALYTICS } from '../../datastore/constants';
import Switch from '../../../../components/Switch';
import { trackEvent } from '../../../../util';
import ViewContextContext from '../../../../components/Root/ViewContextContext';
const { useSelect, useDispatch } = Data;

export default function UseSnippetSwitch() {
	const viewContext = useContext( ViewContextContext );
	const useSnippet = useSelect( ( select ) =>
		select( MODULES_ANALYTICS ).getUseSnippet()
	);
	const canUseSnippet = useSelect( ( select ) =>
		select( MODULES_ANALYTICS ).getCanUseSnippet()
	);
	const hasExistingTag = useSelect( ( select ) =>
		select( MODULES_ANALYTICS ).hasExistingTag()
	);
	const existingTag = useSelect( ( select ) =>
		select( MODULES_ANALYTICS ).getExistingTag()
	);
	const propertyID = useSelect( ( select ) =>
		select( MODULES_ANALYTICS ).getPropertyID()
	);

	const toggleSwitchDisabled = hasExistingTag && existingTag === propertyID;

	const { setUseSnippet } = useDispatch( MODULES_ANALYTICS );
	const onChange = useCallback( () => {
		const newUseSnippet = ! useSnippet;
		setUseSnippet( newUseSnippet );
		trackEvent(
			`${ viewContext }_analytics`,
			newUseSnippet ? 'enable_tag' : 'disable_tag',
			'ua'
		);
	}, [ useSnippet, setUseSnippet, viewContext ] );

	useEffect( () => {
		if ( toggleSwitchDisabled ) {
			setUseSnippet( false );
		}
	}, [ setUseSnippet, toggleSwitchDisabled ] );

	if ( useSnippet === undefined || hasExistingTag === undefined ) {
		return null;
	}

	return (
		<div className="googlesitekit-analytics-usesnippet">
			<Switch
				label={ __(
					'Place Universal Analytics code',
					'google-site-kit'
				) }
				checked={ useSnippet }
				onClick={ onChange }
				hideLabel={ false }
				disabled={ ! canUseSnippet || toggleSwitchDisabled }
			/>
			<p>
				{ canUseSnippet === false && (
					<span>
						{ __(
							'The code is controlled by the Tag Manager module.',
							'google-site-kit'
						) }{ ' ' }
					</span>
				) }
				{ canUseSnippet && useSnippet && (
					<span>
						{ __(
							'Site Kit will add the UA code automatically.',
							'google-site-kit'
						) }{ ' ' }
					</span>
				) }
				{ canUseSnippet && ! useSnippet && (
					<span>
						{ __(
							'Site Kit will not add the UA code to your site.',
							'google-site-kit'
						) }{ ' ' }
					</span>
				) }
			</p>
		</div>
	);
}
