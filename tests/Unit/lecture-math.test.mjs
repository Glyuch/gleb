import assert from 'node:assert/strict';
import test from 'node:test';
import { projectSavings } from '../../resources/js/pages/lecture/finance.ts';
import { scenes } from '../../resources/js/pages/lecture/scenes.ts';
test('zero return equals own contributions', () => { assert.equal(projectSavings(100000,10000,10,0).total,1300000); });
test('effective annual return compounds exactly over one year', () => { assert.ok(Math.abs(projectSavings(100000,0,1,10).total-110000)<0.001); });
test('payments are made at the end of each month', () => { const a = 12; const rate=(1+a/100)**(1/12)-1; const result=projectSavings(0,1000,1,a); let expected=0; for(let i=0;i<12;i++) expected=expected*(1+rate)+1000; assert.ok(Math.abs(result.total-expected)<0.001); });
test('negative scenario can reduce principal', () => { assert.ok(projectSavings(100000,0,5,-5).growth<0); });
test('lecture is exactly forty minutes', () => { assert.equal(scenes.reduce((sum,s)=>sum+s.minutes,0),40); });
