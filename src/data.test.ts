import { describe, it, expect } from 'vitest';
import { normalizeImport, initialLeads } from './data';
describe('lead import',()=>{
 it('rejects invalid records without dropping valid rows',()=>{const result=normalizeImport([{name:''},{name:'Bakery',rating:4.5},{name:'Bad rating',rating:9}],[]);expect(result.accepted).toHaveLength(1);expect(result.failed).toBe(2)});
 it('skips duplicates within the same import and existing records',()=>{const result=normalizeImport([{name:'BLUE BOTTLE COFFEE',city:'San Francisco'},{name:'Local bakery',city:'Austin'},{name:'Local bakery',city:'Austin'}],initialLeads);expect(result.duplicates).toBe(2);expect(result.accepted).toHaveLength(1)});
 it('maps extension ratings and preserves dynamic fields',()=>{const result=normalizeImport([{name:'New business',averageRating:'4.8',reviewCount:'120',weeklyHours:{Monday:'9–5'},placeID:'abc'}],[]);expect(result.accepted[0].rating).toBe(4.8);expect(result.accepted[0].reviews).toBe(120);expect(result.accepted[0].raw?.placeID).toBe('abc')});
});
