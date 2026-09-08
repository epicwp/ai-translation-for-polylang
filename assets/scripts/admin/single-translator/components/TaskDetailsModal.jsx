/**
 * Component for modal showing task error details.
 */

import { useState, useEffect } from '@wordpress/element';
import { Modal, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getJobTasks } from '../utils/api';

/**
 * Task details modal component.
 *
 * @param {Object} props - Component props
 * @param {number} props.jobId - Job ID
 * @param {string} props.languageName - Language name
 * @param {Function} props.onClose - Callback when modal is closed
 * @returns {JSX.Element} The component
 */
export function TaskDetailsModal({ jobId, languageName, onClose }) {
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [tasks, setTasks] = useState([]);

	/**
	 * Fetch task details.
	 */
	useEffect(() => {
		const fetchTasks = async () => {
			try {
				setLoading(true);
				setError(null);
				const data = await getJobTasks(jobId);
				setTasks(data.tasks || []);
			} catch (err) {
				setError(err.message);
			} finally {
				setLoading(false);
			}
		};

		fetchTasks();
	}, [jobId]);

	// Filter tasks with errors
	const errorTasks = tasks.filter((task) => task.issue);

	return (
		<Modal
			title={
				// translators: %s is the language name
				__('Error Details: %s', 'polylang-ai-automatic-translation').replace('%s', languageName)
			}
			onRequestClose={onClose}
			className="pllat-task-details-modal"
		>
			{loading && (
				<div style={{ padding: '20px', textAlign: 'center' }}>
					<Spinner />
				</div>
			)}

			{error && (
				<Notice status="error" isDismissible={false}>
					{error}
				</Notice>
			)}

			{!loading && !error && (
				<div>
					{errorTasks.length === 0 && <p>{__('No error details available.', 'polylang-ai-automatic-translation')}</p>}

					{errorTasks.length > 0 && (
						<div className="pllat-task-error-list">
							{errorTasks.map((task, index) => (
								<div
									key={task.id}
									style={{
										marginBottom: '15px',
										padding: '12px',
										border: '1px solid #dcdcde',
										borderRadius: '4px',
										backgroundColor: '#fff',
									}}
								>
									<div style={{ marginBottom: '8px' }}>
										<strong>
											{__('Task', 'polylang-ai-automatic-translation')} #{index + 1}: {task.title || __('Untitled', 'polylang-ai-automatic-translation')}
										</strong>
									</div>
									<div style={{ fontSize: '13px', color: '#d63638' }}>
										<strong>{__('Error:', 'polylang-ai-automatic-translation')}</strong> {task.issue}
									</div>
									{task.task_type && (
										<div style={{ fontSize: '12px', color: '#757575', marginTop: '5px' }}>
											{__('Type:', 'polylang-ai-automatic-translation')} {task.task_type}
										</div>
									)}
								</div>
							))}
						</div>
					)}
				</div>
			)}
		</Modal>
	);
}

export default TaskDetailsModal;
