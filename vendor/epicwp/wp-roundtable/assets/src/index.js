/** @jsx h */
import { h, render } from 'preact';
import { CommunityApp } from './community-app.jsx';
import { initCodeCopyDelegation } from './code-blocks.js';
import './styles.css';

initCodeCopyDelegation();

const root = document.getElementById('roundtable-app');
if (root) render(h(CommunityApp, {}), root);
