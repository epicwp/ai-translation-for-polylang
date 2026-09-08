import { useState, useEffect } from '@wordpress/element';
import { CheckboxControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export default function FieldSelector({ contentType, contentId, selectedFields, onChange }) {
    const [fields, setFields] = useState({ content: [], meta: [] });
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');

    useEffect(() => {
        setLoading(true);
        const idParam = contentId ? `?id=${contentId}` : '';
        apiFetch({
            path: `/pllat/v1/content/${contentType.type}/${contentType.entity}/fields${idParam}`,
        })
            .then((data) => {
                setFields(data);
                setLoading(false);
            })
            .catch(() => {
                setFields({ content: [], meta: [] });
                setLoading(false);
            });
    }, [contentType.type, contentType.entity, contentId]);

    // Auto-select all non-protected fields on initial load.
    useEffect(() => {
        if (!loading && selectedFields.length === 0) {
            const allLoaded = [...fields.content, ...fields.meta];
            const nonProtected = allLoaded.filter(f => !f.protected);
            if (nonProtected.length > 0) {
                onChange(nonProtected.map(f => f.key));
            }
        }
    }, [loading]); // Only on load completion

    const allFields = [...fields.content, ...fields.meta];
    const filteredContent = fields.content.filter(
        (f) => f.label.toLowerCase().includes(search.toLowerCase()) || f.key.toLowerCase().includes(search.toLowerCase())
    );
    const filteredMeta = fields.meta.filter(
        (f) => f.label.toLowerCase().includes(search.toLowerCase()) || f.key.toLowerCase().includes(search.toLowerCase())
    );
    const hasResults = filteredContent.length > 0 || filteredMeta.length > 0;

    const nonProtectedFields = allFields.filter(f => !f.protected);
    const allNonProtectedSelected = nonProtectedFields.length > 0 &&
        nonProtectedFields.every(f => selectedFields.includes(f.key));

    const toggleField = (key, checked) => {
        if (checked) {
            onChange([...selectedFields, key]);
        } else {
            onChange(selectedFields.filter((k) => k !== key));
        }
    };

    const toggleAll = () => {
        const protectedSelected = selectedFields.filter(
            key => allFields.find(f => f.key === key && f.protected)
        );
        if (allNonProtectedSelected) {
            onChange(protectedSelected);
        } else {
            onChange([...nonProtectedFields.map(f => f.key), ...protectedSelected]);
        }
    };

    if (loading) {
        return (
            <div style={{
                marginTop: '8px',
                padding: '16px',
                backgroundColor: '#f9fafb',
                border: '1px solid #e5e7eb',
                borderRadius: '6px',
                textAlign: 'center',
            }}>
                <Spinner />
            </div>
        );
    }

    const helpText = selectedFields.length === 0
        ? __('No fields selected. Click "Select all" to select all fields.', 'polylang-ai-automatic-translation')
        : __('Deselect any fields you want to skip.', 'polylang-ai-automatic-translation');

    const renderField = (field) => {
        const isSelected = selectedFields.includes(field.key);
        const isProtected = field.protected;
        return (
            <button
                key={field.key}
                type="button"
                onClick={() => toggleField(field.key, !isSelected)}
                style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: '4px',
                    padding: '3px 10px',
                    borderRadius: '12px',
                    border: isProtected ? '1px dashed #c3c4c7' : 'none',
                    backgroundColor: isSelected ? '#e8f4fc' : (isProtected ? 'transparent' : '#f0f0f1'),
                    color: isSelected ? '#0a4b78' : (isProtected ? '#8c8f94' : '#50575e'),
                    cursor: 'pointer',
                    fontSize: '12px',
                    transition: 'all 0.15s ease',
                    lineHeight: '1.6',
                    opacity: isProtected && !isSelected ? 0.7 : 1,
                }}
                title={isProtected ? __('May affect SEO — select only if needed', 'polylang-ai-automatic-translation') : undefined}
            >
                <span>{field.label}</span>
                {isProtected && !isSelected && (
                    <span className="dashicons dashicons-warning" style={{ fontSize: '12px', width: '12px', height: '12px', color: '#dba617' }} />
                )}
                {isSelected && (
                    <span
                        className="dashicons dashicons-yes-alt"
                        style={{ fontSize: '13px', width: '13px', height: '13px' }}
                    />
                )}
            </button>
        );
    };

    return (
        <div style={{
            marginTop: '8px',
            backgroundColor: '#f9fafb',
            border: '1px solid #e5e7eb',
            borderRadius: '6px',
            overflow: 'hidden',
        }}>
            {/* Header */}
            <div style={{
                padding: '10px 14px',
                borderBottom: '1px solid #e5e7eb',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
            }}>
                <div>
                    <span style={{ fontSize: '13px', fontWeight: '500', color: '#1d2327' }}>
                        {__('Select fields to re-translate', 'polylang-ai-automatic-translation')}
                    </span>
                    <span style={{ fontSize: '12px', color: '#646970', marginLeft: '8px' }}>
                        {helpText}
                    </span>
                </div>
                <button
                    type="button"
                    onClick={toggleAll}
                    style={{
                        background: 'none',
                        border: 'none',
                        color: '#2271b1',
                        cursor: 'pointer',
                        padding: 0,
                        fontSize: '12px',
                        whiteSpace: 'nowrap',
                    }}
                >
                    {allNonProtectedSelected
                        ? __('Deselect all', 'polylang-ai-automatic-translation')
                        : __('Select all', 'polylang-ai-automatic-translation')}
                </button>
            </div>

            {/* Search */}
            {allFields.length > 6 && (
                <div style={{ padding: '8px 14px', borderBottom: '1px solid #e5e7eb' }}>
                    <input
                        type="text"
                        placeholder={__('Search fields...', 'polylang-ai-automatic-translation')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        style={{
                            width: '100%',
                            padding: '4px 8px',
                            border: '1px solid #8c8f94',
                            borderRadius: '4px',
                            fontSize: '12px',
                            boxSizing: 'border-box',
                        }}
                    />
                </div>
            )}

            {/* Field list */}
            <div style={{
                maxHeight: '200px',
                overflowY: 'auto',
                padding: '8px 14px',
            }}>
                {filteredContent.length > 0 && (
                    <div style={{ marginBottom: filteredMeta.length > 0 ? '10px' : 0 }}>
                        <div style={{
                            fontSize: '10px',
                            fontWeight: '600',
                            color: '#8c8f94',
                            textTransform: 'uppercase',
                            letterSpacing: '0.5px',
                            marginBottom: '4px',
                        }}>
                            {__('Content', 'polylang-ai-automatic-translation')}
                        </div>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px' }}>
                            {filteredContent.map(renderField)}
                        </div>
                    </div>
                )}

                {filteredMeta.length > 0 && (
                    <div>
                        <div style={{
                            fontSize: '10px',
                            fontWeight: '600',
                            color: '#8c8f94',
                            textTransform: 'uppercase',
                            letterSpacing: '0.5px',
                            marginBottom: '4px',
                        }}>
                            {__('Meta Fields', 'polylang-ai-automatic-translation')}
                        </div>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px' }}>
                            {filteredMeta.map(renderField)}
                        </div>
                    </div>
                )}

                {!hasResults && search && (
                    <p style={{ fontSize: '12px', color: '#646970', margin: '8px 0' }}>
                        {__('No fields match your search.', 'polylang-ai-automatic-translation')}
                    </p>
                )}
            </div>

            {/* Footer: selection count */}
            {selectedFields.length > 0 && (
                <div style={{
                    padding: '8px 14px',
                    borderTop: '1px solid #e5e7eb',
                    fontSize: '12px',
                    color: '#2271b1',
                    fontWeight: '500',
                }}>
                    {`${selectedFields.length} ${selectedFields.length === 1
                        ? __('field', 'polylang-ai-automatic-translation')
                        : __('fields', 'polylang-ai-automatic-translation')
                    } selected`}
                </div>
            )}
        </div>
    );
}
